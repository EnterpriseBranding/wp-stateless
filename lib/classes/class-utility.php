<?php
/**
 * Helper Functions List
 *
 * Can be called via Singleton. Since Singleton uses magic method __call().
 * Example:
 *
 * Add Media to GS storage:
 * ud_get_stateless_media()->add_media( false, $post_id );
 *
 * @class Utility
 */
namespace wpCloud\StatelessMedia {

  if( !class_exists( 'wpCloud\StatelessMedia\Utility' ) ) {

    class Utility {

      static $can_delete_attachment = [];
      static $synced_sizes = [];

      /**
       * ChromeLogger
       *
       * @author potanin@UD
       * @param $data
       */
      static public function log( $data ) {

        if( !class_exists( 'wpCloud\StatelessMedia\Logger' )) {
          include_once( __DIR__ . '/class-logger.php' );
        }

        if( !class_exists( 'wpCloud\StatelessMedia\Logger' )) {
          return;
        }

        if( defined( 'WP_STATELESS_CONSOLE_LOG' ) && WP_STATELESS_CONSOLE_LOG ) {
          Logger::log( '[wp-stateless]', $data );
        }

      }

      /**
       * Override Cache Control
       * @param $cacheControl
       * @return mixed
       */
      public static function override_cache_control( $cacheControl ) {
        return ud_get_stateless_media()->get( 'sm.cache_control' );
      }

      /**
       * wp_normalize_path was added in 3.9.0
       *
       * @param $path
       * @return mixed|string
       *
       */
      public static function normalize_path( $path ) {

        if( function_exists( 'wp_normalize_path' ) ) {
          return wp_normalize_path( $path );
        }

        $path = str_replace( '\\', '/', $path );
        $path = preg_replace( '|/+|','/', $path );
        return $path;

      }

      /**
       * Randomize file name
       * @param $filename
       * @return string
       */
      public static function randomize_filename( $filename ) {
        $return = apply_filters('stateless_skip_cache_busting', null, $filename);
        if($return){
          return $return;
        }

        if(preg_match('/^[a-f0-9]{8}-/', $filename)){
          return $filename;
        }

        $info = pathinfo($filename);
        $ext = empty($info['extension']) ? '' : '' . $info['extension'];
        $_parts = array();
        $rand = substr(md5(time()), 0, 8);

        if (strpos($info['filename'], '@')) {
          $_cleanName = explode('@', $info['filename'])[0];
          $_retna = explode('@', $info['filename'])[1];
          $_parts[] = $rand;
          $_parts[] = '-';
          $_parts[] = strtolower($_cleanName);
          $_parts[] = '@' . strtolower($_retna);
        } else {
          $_parts[] = $rand;
          $_parts[] = '-';
          $_parts[] = strtolower($info['filename']);
        }

        $filename = join('', $_parts);
        if(!empty($ext)){
          $filename .= '.' . $ext;
        }

        return $filename;
      }

      /**
       * Get Media Item Content Disposition
       *
       * @param null $attachment_id
       * @param array $metadata
       * @param array $data
       * @return string
       */
      public static function getContentDisposition( $attachment_id = null, $metadata = array(), $data = array() ) {
        // return 'Content-Disposition: attachment; filename=some-file.sql';

        return apply_filters( 'sm:item:contentDisposition', null, array( 'attachment_id' => $attachment_id, 'mime_type' => get_post_mime_type( $attachment_id ), 'metadata' => $metadata, 'data' => $data ) );

      }

      /**
       * @param null $attachment_id
       * @param array $metadata
       * @param array $data
       * @return string
       */
      public static function getCacheControl( $attachment_id = null, $metadata = array(), $data = array() ) {

        if( !$attachment_id ) {
          return apply_filters( 'sm:item:cacheControl', 'private, no-cache, no-store', $attachment_id, array( 'attachment_id' => null, 'mime_type' => null, 'metadata' => $metadata, 'data' => $data ) );
        }

        $_mime_type = get_post_mime_type( $attachment_id );

        // Treat images as public.
        if( strpos( $_mime_type, 'image/' ) !== false ) {
          return apply_filters( 'sm:item:cacheControl', 'public, max-age=36000, must-revalidate', array( 'attachment_id' => $attachment_id, 'mime_type' => null, 'metadata' => $metadata, 'data' => $data ) );
        }

        // Treat images as public.
        if( strpos( $_mime_type, 'sql' ) !== false ) {
          return apply_filters( 'sm:item:cacheControl', 'private, no-cache, no-store', array( 'attachment_id' => $attachment_id, 'mime_type' => null, 'metadata' => $metadata, 'data' => $data ) );
        }

        return apply_filters( 'sm:item:cacheControl', 'public, max-age=30, no-store, must-revalidate', array( 'attachment_id' => $attachment_id, 'mime_type' => null, 'metadata' => $metadata, 'data' => $data ) );

      }

      /**
       * Add/Update Media to Bucket
       * Fired for every action with image add or update
       *
       * $force and $args params will no be passed on media library uploads.
       * This two will be passed on by compatibility.
       *
       * @action wp_generate_attachment_metadata
       * @author peshkov@UD
       * @param $metadata
       * @param $attachment_id
       * @param $force Whether to force the upload incase of it's already exists.
       * @param $args Whether to only sync the full size image.
       * @return bool|string
       */
      public static function add_media( $metadata, $attachment_id, $force = false, $args = array() ) {
        global $stateless_synced_full_size;
        $file = '';
        $upload_dir = wp_upload_dir();
        $args = wp_parse_args($args, array(
          'no_thumb' => false,
          'is_webp' => '', // expected value ".webp";
        ));

        /* Get metadata in case if method is called directly. */
        if( current_filter() !== 'wp_generate_attachment_metadata' && current_filter() !== 'wp_update_attachment_metadata' && current_filter() !== 'intermediate_image_sizes_advanced' ) {
          $metadata = wp_get_attachment_metadata( $attachment_id );
        }

        // making sure meta data isn't null.
        if(empty($metadata)){
          $metadata = array();
        }

        /**
         * To skip the sync process.
         *
         * Returning a non-null value
         * will effectively short-circuit the function.
         *
         * $force and $args params will no be passed on non media library uploads.
         * This two will be passed on by compatibility.
         *
         * @since 2.2.4
         *
         * @param bool              $value          This should return true if want to skip the sync.
         * @param int               $metadata       Metadata for the attachment.
         * @param string            $attachment_id  Attachment ID.
         * @param bool              $force          (optional) Whether to force the sync even the file already exist in GCS.
         * @param bool              $args           (optional) Whether to only sync the full size image.
         */
        $check = apply_filters('wp_stateless_skip_add_media', null, $metadata, $attachment_id, $force, $args);

        $client = ud_get_stateless_media()->get_client();

        if( !is_wp_error( $client ) && !$check ) {

          $image_host          = ud_get_stateless_media()->get_gs_host();
          $bucketLink          = apply_filters('wp_stateless_bucket_link', $image_host);
          $fullsizepath        = wp_normalize_path( get_attached_file( $attachment_id ) );
          $_cacheControl       = self::getCacheControl( $attachment_id, $metadata, null );
          $_contentDisposition = self::getContentDisposition( $attachment_id, $metadata, null );

          // Ensure image upload to GCS when attachment is updated,
          // by checking if the attachment metadata is changed.
          if($attachment_id && !empty($metadata) && !$force){
            $db_metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
            if($db_metadata != $metadata){
              $force = true;
            }
          }

          // Make non-images uploadable.
          // empty $metadata['file'] can cause problem, so we need to generate it.
          if( empty( $metadata['file'] ) && $attachment_id ) {
            $mime_type = get_post_mime_type( $attachment_id );
            $file = str_replace( wp_normalize_path(trailingslashit( $upload_dir[ 'basedir' ] )), '', $fullsizepath );

            // We shouldn't create $metadata["file"] if it's PDF file.
            if($mime_type != "application/pdf"){
              $metadata["file"] = $file;
            }
          }

          $cloud_meta = get_post_meta( $attachment_id, 'sm_cloud', true );

          $cloud_meta = wp_parse_args($cloud_meta, array(
            'id'                 => '',
            'name'               => '',
            'bucket'             => ud_get_stateless_media()->get( 'sm.bucket' ),
            'storageClass'       => '',
            'fileLink'           => '',
            'mediaLink'          => '',
            'selfLink'           => '',
            'cacheControl'       => $_cacheControl,
            'contentDisposition' => $_contentDisposition,
            'object'             => '',
            'sizes'              => array(),
          ));

          /**
           * Storing file size to sm_cloud first,
           * Because assigning directly to $metadata['filesize'] don't work.
           * Maybe filesize gets removed in first run (when file exists).
           */
          if ( file_exists( $fullsizepath ) ) {
            $cloud_meta['filesize'] = filesize( $fullsizepath );
          }
          // Getting file size from sm_cloud.
          if(!empty($cloud_meta['filesize'])){
            $metadata['filesize'] = $cloud_meta['filesize'];
          }

          /**
           *
           */
          $image_sizes = self::get_path_and_url($metadata, $attachment_id);
          foreach($image_sizes as $size => $img){
            // also skips full size image if already uploaded using that feature.
            // and delete it in stateless mode as it already bin uploaded through intermediate_image_sizes_advanced filter.
            if( !$img['is_thumb'] && $stateless_synced_full_size == $attachment_id ){
              if(ud_get_stateless_media()->get( 'sm.mode' ) === 'stateless' && $args['no_thumb'] != true && \file_exists($img['path'])){
                unlink($img['path']);
              }
              continue;
            }

            // skips thumbs when it's called from Upload the full size image first, through intermediate_image_sizes_advanced filter.
            if($args['no_thumb'] && $img['is_thumb'] || !empty(self::$synced_sizes[$attachment_id][$size])){
              continue;
            }

            // GCS metadata
            $_metadata = array(
              "width"     => $img[ 'width' ],
              "height"    => $img[ 'height' ],
              'child-of'  => $attachment_id,
              'file-hash' => md5( $file ),
            );

            // adding extra GCS meta for full size image.
            if(!$img['is_thumb']){
              unset($_metadata['child-of']); // no need in full size image.
              $_metadata['object-id'] = $attachment_id;
              $_metadata['source-id'] = md5( $attachment_id.ud_get_stateless_media()->get( 'sm.bucket' ) );
            }

            /* Add default image */
            $media = $client->add_media( array_filter( array(
              'force'              => $img['is_thumb'] ? $force : $force && $stateless_synced_full_size != $attachment_id,
              'name'               => $img['gs_name'],
              'is_webp'            => $args['is_webp'],
              'mimeType'           => $img['mime_type'],
              'metadata'           => $_metadata,
              'absolutePath'       => $img['path'],
              'cacheControl'       => $_cacheControl,
              'contentDisposition' => $_contentDisposition,
            ) ));

            /* Break if we have errors. */
            if( !is_wp_error( $media ) ) {
              // @note We don't add storageClass because it's same as parent...
              $cloud_meta = self::generate_cloud_meta($cloud_meta, $media, $size, $img, $bucketLink);

              // Stateless mode: we don't need the local version.
              // Except when uploading the full size image first.
              if(self::can_delete_attachment($attachment_id, $args)){
                unlink($img['path']);
              }

              // Setting
              if(empty(self::$synced_sizes[$attachment_id][$size])){
                self::$synced_sizes[$attachment_id][$size] = true;
              }
            }
          }
          // End of image sync loop

          if(!$args['is_webp']){
            update_post_meta( $attachment_id, 'sm_cloud', $cloud_meta );
          }
          else{
            // There is no use case for is_webp meta.
            // $cloud_meta = get_post_meta( $attachment_id, 'sm_cloud', true);
            // $cloud_meta['is_webp'] = true;
            // update_post_meta( $attachment_id, 'sm_cloud', $cloud_meta );
          }

          if($args['no_thumb'] == true){
            $stateless_synced_full_size = $attachment_id;
          }

          /**
           * Triggers when the media and it's thumbs are synced.
           *
           * $force and $args params will no be passed on non media library uploads.
           * This two will be passed on by compatibility.
           *
           * @since 2.2.5
           *
           * @param int               $metadata       Metadata for the attachment.
           * @param string            $attachment_id  Attachment ID.
           * @param bool              $force          (optional) Whether to force the sync even the file already exist in GCS.
           * @param bool              $args           (optional) Whether to only sync the full size image.
           */
          do_action( 'wp_stateless_media_synced', $metadata, $attachment_id, $force, $args);
        }

        return $metadata;
      }

      /**
       * Remove Media from Bucket by post ID
       * Fired on calling function wp_delete_attachment()
       *
       * @todo: add error logging. peshkov@UD
       * @see wp_delete_attachment()
       * @action delete_attachment
       * @author peshkov@UD
       * @param $post_id
       */
      public static function remove_media( $post_id ) {
        /* Get attachments metadata */
        $metadata = wp_get_attachment_metadata( $post_id );

        /* Be sure we have the same bucket in settings and have GS object's name before proceed. */
        if(
          isset( $metadata[ 'gs_name' ] ) &&
          isset( $metadata[ 'gs_bucket' ] ) &&
          $metadata[ 'gs_bucket' ] == ud_get_stateless_media()->get( 'sm.bucket' )
        ) {

          $client = ud_get_stateless_media()->get_client();
          if( !is_wp_error( $client ) ) {

            /* Remove default image */
            $client->remove_media( $metadata[ 'gs_name' ] );
            // Remove webp
            $client->remove_media( $metadata[ 'gs_name' ] . '.webp' );

            /* Now, go through all sizes and remove 'image sizes' images from Bucket too. */
            if( !empty( $metadata[ 'sizes' ] ) && is_array( $metadata[ 'sizes' ] ) ) {
              foreach( $metadata[ 'sizes' ] as $k => $v ) {
                if( !empty( $v[ 'gs_name' ] ) ) {
                  $client->remove_media( $v[ 'gs_name' ] );
                  $client->remove_media( $v[ 'gs_name' ] . '.webp' );
                }
              }
            }

          }

        }

      }

      /**
       * Return URL and path for all image sizes of a attachment.
       */
      public static function get_path_and_url( $metadata, $attachment_id ){
        /* Get metadata in case if method is called directly. */
        if( empty($metadata) && current_filter() !== 'wp_generate_attachment_metadata' && current_filter() !== 'wp_update_attachment_metadata' ) {
          $metadata = wp_get_attachment_metadata( $attachment_id );
        }

        $gs_name_path   = array();
        $full_size_path = get_attached_file( $attachment_id );
        $base_dir       = dirname( $full_size_path );
        $gs_name        = apply_filters('wp_stateless_file_name', $full_size_path);

        if( !isset($metadata['width']) && file_exists($full_size_path) ){
          try{
            $_image_size = getimagesize($full_size_path);
            $metadata['width']  = $_image_size[0];
            $metadata['height'] = $_image_size[1];
          }
          catch(Exception $e){
            // lets do nothing.
          }
        }


        $gs_name_path['__full'] = array(
          'gs_name'   => $gs_name,
          'path'      => $full_size_path,
          'sm_meta'   => true,
          'is_thumb'  => false,
          'mime_type' => get_post_mime_type( $attachment_id ),
          'width'     => isset($metadata['width']) ? $metadata['width'] : null,
          'height'    => isset($metadata['height']) ? $metadata['height'] : null,
        );


        /* Now we go through all available image sizes and upload them to Google Storage */
        if( !empty( $metadata[ 'sizes' ] ) && is_array( $metadata[ 'sizes' ] ) ) {
          foreach( $metadata[ 'sizes' ] as $image_size => $data ) {
            if(empty($data[ 'file' ])) continue;
            $absolutePath = wp_normalize_path( $base_dir . '/' . $data[ 'file' ] );
            $gs_name = apply_filters('wp_stateless_file_name', $absolutePath);

            $gs_name_path[$image_size] = array(
              'gs_name'   => $gs_name,
              'path'      => $absolutePath,
              'sm_meta'   => true,
              'is_thumb'  => true,
              'mime_type' => $data['mime-type'],
              'width'     => $data['width'],
              'height'    => $data['height'],
            );
          }
        }

        return apply_filters( 'wp_stateless_get_path_and_url', $gs_name_path, $metadata, $attachment_id );
      }

      /**
       * Return URL and path for all image sizes of a attachment.
       */
      public static function generate_cloud_meta( $cloud_meta, $media, $image_size, $img, $bucketLink ){
        $gs_name = !empty($media['name']) ? $media['name'] : $img['gs_name'];
        $fileLink = trailingslashit($bucketLink) . $gs_name;

        if($img['is_thumb']){
          // Cloud meta for thumbs.
          $cloud_meta[ 'sizes' ][ $image_size ]['id']           = $media[ 'id' ];
          $cloud_meta[ 'sizes' ][ $image_size ]['name']         = $gs_name;
          $cloud_meta[ 'sizes' ][ $image_size ]['fileLink']     = $fileLink;
          $cloud_meta[ 'sizes' ][ $image_size ]['mediaLink']    = $media[ 'mediaLink' ];
          $cloud_meta[ 'sizes' ][ $image_size ]['selfLink']     = $media[ 'selfLink' ];
        }
        else{
          // cloud meta for full size image.
          $cloud_meta['id']                     = $media[ 'id' ];
          $cloud_meta['name']                   = $gs_name;
          $cloud_meta['fileLink']               = $fileLink;
          $cloud_meta['storageClass']           = $media[ 'storageClass' ];
          $cloud_meta['mediaLink']              = $media[ 'mediaLink' ];
          $cloud_meta['selfLink']               = $media[ 'selfLink' ];
          $cloud_meta['bucket']                 = ud_get_stateless_media()->get( 'sm.bucket' );
          $cloud_meta['object']                 = $media;
        }
        return apply_filters( 'wp_stateless_generate_cloud_meta', $cloud_meta, $media, $image_size, $img, $bucketLink );
      }

      /**
       * join_url
       *
       * @param array $parts
       * @param boolean $encode
       * @return string $url
       */
      public static function join_url( $parts, $encode=TRUE ){
        if ( $encode ){
          if ( isset( $parts['user'] ) )
            $parts['user']     = rawurlencode( $parts['user'] );
          if ( isset( $parts['pass'] ) )
            $parts['pass']     = rawurlencode( $parts['pass'] );
          if ( isset( $parts['host'] ) &&
            !preg_match( '!^(\[[\da-f.:]+\]])|([\da-f.:]+)$!ui', $parts['host'] ) )
            $parts['host']     = rawurlencode( $parts['host'] );
          if ( !empty( $parts['path'] ) )
            $parts['path']     = preg_replace( '!%2F!ui', '/',
              rawurlencode( $parts['path'] ) );
          if ( isset( $parts['query'] ) )
            $parts['query']    = rawurlencode( $parts['query'] );
          if ( isset( $parts['fragment'] ) )
            $parts['fragment'] = rawurlencode( $parts['fragment'] );
        }

        $url = '';
        if ( !empty( $parts['scheme'] ) )
          $url .= $parts['scheme'] . ':';
        if ( isset( $parts['host'] ) ){
          $url .= '//';
          if ( isset( $parts['user'] ) ){
            $url .= $parts['user'];
            if ( isset( $parts['pass'] ) )
              $url .= ':' . $parts['pass'];
            $url .= '@';
          }
          if ( preg_match( '!^[\da-f]*:[\da-f.:]+$!ui', $parts['host'] ) )
            $url .= '[' . $parts['host'] . ']'; // IPv6
          else
            $url .= $parts['host'];             // IPv4 or name
          if ( isset( $parts['port'] ) )
            $url .= ':' . $parts['port'];
          if ( !empty( $parts['path'] ) && $parts['path'][0] != '/' )
            $url .= '/';
        }
        if ( !empty( $parts['path'] ) )
          $url .= $parts['path'];
        if ( isset( $parts['query'] ) )
          $url .= '?' . $parts['query'];
        if ( isset( $parts['fragment'] ) )
          $url .= '#' . $parts['fragment'];
        return $url;
      }

      /**
       * add_webp_mime
       *
       */
      public function add_webp_mime($t, $user){
        $t['webp'] = 'image/webp';
        return $t;
      }

      /**
       * Store attachment id in a static variable on 'intermediate_image_sizes_advanced' filter.
       * To indicate that we can now delete attachment from server now.
       *
       * @param array $new_sizes
       * @param array $image_meta
       * @param int $attachment_id
       * @return array $new_sizes
       */
      public static function store_can_delete_attachment( $new_sizes, $image_meta, $attachment_id ){
        if( !in_array($attachment_id, self::$can_delete_attachment)){
          self::$can_delete_attachment[] = $attachment_id;
        }
        return $new_sizes;
      }

      /**
       * Check whether to delete attachment from server or not.
       *
       * @param int $attachment_id
       * @return boolean
       */
      public static function can_delete_attachment($attachment_id, $args){
        if(
          ud_get_stateless_media()->get( 'sm.mode' ) === 'stateless' &&
          $args['no_thumb'] != true
        ){
          // checks whether it's WP 5.3 and 'intermediate_image_sizes_advanced' is passed.
          // To be sure that we don't delete full size image before thumbnails are generated.
          if(
            wp_attachment_is_image($attachment_id) &&
            function_exists('is_wp_version_compatible') &&
            is_wp_version_compatible('5.3-RC4-46673') &&
            !in_array($attachment_id, self::$can_delete_attachment)
          ){
            return false;
          }
          return true;
        }
        return false;
      }

      /**
       * Useful when there is a need to do things depending on a call stack.
       * Returns true if any of the conditions met. Returns false otherwise.
       *
       * @param $callstack array Result of debug_backtrace function.
       * @param $conditions array CallStack fingerprint with `stack_level` integer.
       *
       * Example:
       * array(
       *  array(
       *    'stack_level' => 4,
       *    'function' => '__construct',
       *    'class' => 'ET_Core_PageResource'
       *  ),
       *  array(
       *    'stack_level' => 4,
       *    'function' => 'get_cache_filename',
       *    'class' => 'ET_Builder_Element'
       *  )
       * )
       *
       * @return bool
       */
      public static function isCallStackMatches( $callstack, $conditions ) {
        if ( !is_array( $conditions ) ) {
          $conditions = array( $conditions );
        }

        foreach( $conditions as $condition ) {
          $condition['stack_level'] = $condition['stack_level'] ? $condition['stack_level'] : 0;

          $levelData = $callstack[$condition['stack_level']];

          unset( $condition['stack_level'] );

          $levelMatches = false;
          foreach( $condition as $key => $value ) {
            if ( isset($levelData[ $key ]) && $levelData[ $key ] === $value ) {
              $levelMatches = true;
            } else {
              $levelMatches = false;
            }
          }

          if ( $levelMatches ) return true;
        }

        return false;
      }

      /**
       * Fail over to image URL if not found on disk
       * In case image not available on both local and bucket
       * try to pull image from image URL in case it is accessible by some sort of proxy.
       *
       * @param:
       * $url (int/string): URL of the image.
       * $save_to (string): Path where to save the image.
       *
       * @return bool|int
       * @throws \Exception
       */
      public static function sync_get_attachment_if_exist($url, $save_to){
        if(is_int($url))
          $url = wp_get_attachment_url($url);

        $response = wp_remote_get( $url );
        if ( !is_wp_error($response) && is_array( $response ) ) {
          if(!empty($response['response']['code']) && $response['response']['code'] == 200){
            try{
              if(wp_mkdir_p(dirname($save_to))){
                return file_put_contents($save_to, $response['body']);
              }
            }
            catch(\Exception $e){
              throw $e;
            }
          }
        }
        return false;
      }

      /**
       * Store failed attachment
       * @param $attachment_id
       * @param $mode
       */
      public static function sync_store_failed_attachment( $attachment_id, $mode ) {
        if ( ! in_array( $mode, [ 'other', 'cli_images', 'cli_other' ] ) ) {
          $mode = 'images';
        }

        $fails = get_option( 'wp_stateless_failed_' . $mode );
        if ( !empty( $fails ) && is_array( $fails ) ) {
          if ( !in_array( $attachment_id, $fails ) ) {
            $fails[] = $attachment_id;
          }
        } else {
          $fails = array( $attachment_id );
        }

        update_option( 'wp_stateless_failed_' . $mode, $fails );
      }

      /**
       * Checking maybe attachment have already fixed
       * @param $mode
       * @param $attachment_id
       */
      public static function sync_maybe_fix_failed_attachment( $mode, $attachment_id ) {
        $fails = get_option( 'wp_stateless_failed_' . $mode );

        if ( !empty( $fails ) && is_array( $fails ) ) {
          if ( in_array( $attachment_id, $fails ) ) {
            foreach (array_keys($fails, $attachment_id) as $key) {
              unset($fails[$key]);
            }
          }
        }

        update_option( 'wp_stateless_failed_' . $mode, $fails );
      }

      /**
       * Store current synchronization progress
       * @param $mode
       * @param $id
       * @param $cli
       */
      public static function sync_store_current_progress( $mode, $id, $cli = false ) {
        if ( ! in_array( $mode, [ 'other', 'cli_images', 'cli_other' ] ) ) {
          $mode = 'images';
        }

        $first_processed = get_option( 'wp_stateless_' . $mode . '_first_processed' );
        if ( ! $first_processed ) {
          update_option( 'wp_stateless_' . $mode . '_first_processed', $id );
        }
        $last_processed = get_option( 'wp_stateless_' . $mode . '_last_processed' );
        if ( ! $last_processed || $id < (int) $last_processed || $cli ) {
          update_option( 'wp_stateless_' . $mode . '_last_processed', $id );
        }
      }

      /**
       * Get synchronization progress
       * @param $mode
       * @return array|bool
       */
      public static function sync_retrieve_current_progress( $mode ) {
        if ( ! in_array( $mode, [ 'other', 'cli_images', 'cli_other' ] ) ) {
          $mode = 'images';
        }

        $first_processed = get_option( 'wp_stateless_' . $mode . '_first_processed' );
        $last_processed = get_option( 'wp_stateless_' . $mode . '_last_processed' );

        if ( ! $first_processed || ! $last_processed ) {
          return false;
        }

        return array( (int) $first_processed, (int) $last_processed );
      }

      /**
       * Reset synchronization progress
       * @param $mode
       */
      public static function sync_reset_current_progress( $mode ) {
        if ( ! in_array( $mode, [ 'other', 'cli_images', 'cli_other' ] ) ) {
          $mode = 'images';
        }

        delete_option( 'wp_stateless_' . $mode . '_first_processed' );
        delete_option( 'wp_stateless_' . $mode . '_last_processed' );
      }

      /**
       * Get fails
       *
       * @param $mode
       * @return mixed|void
       */
      public static function sync_get_fails( $mode ) {
        if ( ! in_array( $mode, [ 'other', 'cli_images', 'cli_other' ] ) ) {
          $mode = 'images';
        }

        return get_option( 'wp_stateless_failed_' . $mode );
      }

      /**
       * Get_non_processed_media_ids
       *
       * @param $mode
       * @param $files
       * @param bool $continue
       * @param $start_from
       * @return array
       * @throws \Exception
       */
      public static function sync_get_non_processed_media_ids( $mode, $files, $continue = false, $start_from = 0 ) {
        if(ud_get_stateless_media()->is_connected_to_gs() !== true){
          throw new \Exception( __( 'Not connected to GCS', ud_get_stateless_media()->domain) );
        }

        if ( $continue ) {
          $progress = self::sync_retrieve_current_progress( $mode );

          if ( false !== $progress ) {
            if($start_from && $start_from != 0){
              // adding 1 because we subtracted 1 in js code for presentation.
              $progress[1] = $start_from + 1;
            }
            $ids = array();
            foreach ( $files as $file ) {
              $id = (int) $file->ID;
              // only include IDs that have not been processed yet
              if ( $id > $progress[0] || $id < $progress[1] ) {
                $ids[] = $id;
              }
            }
            return $ids;
          }
        }

        self::sync_reset_current_progress( $mode );

        $ids = array();
        foreach ( $files as $file )
          $ids[] = (int)$file->ID;

        return $ids;
      }

    }
  }
}