<?php

namespace Drupal\content_sync\Normalizer;

use Drupal\content_sync\Plugin\SyncNormalizerDecoratorManager;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * Adds the file URI to embedded file entities.
 */
class FileEntityNormalizer extends ContentEntityNormalizer {

  /**
   * The interface or class that this Normalizer supports.
   *
   * @var string
   */
  protected $supportedInterfaceOrClass = 'Drupal\file\FileInterface';

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * FileEntityNormalizer constructor.
   *
   * @param EntityManagerInterface $entity_manager
   *
   * @param SyncNormalizerDecoratorManager $decorator_manager
   *
   * @param FileSystemInterface $file_system
   */
  public function __construct(EntityManagerInterface $entity_manager, SyncNormalizerDecoratorManager $decorator_manager, FileSystemInterface $file_system) {
    parent::__construct($entity_manager, $decorator_manager);
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $serializer_context = array()) {

    $file_data = '';

    // Check if the image is available as base64-encoded image.
    if (!empty($data['data'][0]['value'])) {
      $file_data = $data['data'][0]['value'];
      // Avoid 'data' being treated as a field.
      unset($data['data']);
    }

    // If a directory is set, we must to copy the file to the file system.
    if (!empty($serializer_context['content_sync_directory_files'])) {
      $file_path = realpath($serializer_context['content_sync_directory_files']);
      if ($file_path === FALSE && file_exists($serializer_context['content_sync_directory_files'])) {
        // XXX: 'realpath' returned FALSE; however, the path appears to exist.
        // It's likely a URI making use of a stream wrapper for which
        // calculating a "realpath" does not make sense... ideally there would
        // be some other canonicalization which might be used to deal with
        // relative path components; however, let's just pass it along as-is
        // for now.
        $file_path = $serializer_context['content_sync_directory_files'];
      }

      $scheme = $this->fileSystem->uriScheme($data['uri'][0]['value']);
      if (!empty($scheme)) {
        $source_path = "$file_path/$scheme/";
        $source      = str_replace("$scheme://", $source_path, $data['uri'][0]['value']);
        if (file_exists($source)) {
          $file = $data['uri'][0]['value'];
          if (!file_exists($file) || !$this->compareFiles($file, $source)) {
            $dir = $this->fileSystem->dirname($data['uri'][0]['value']);
            file_prepare_directory($dir, FILE_CREATE_DIRECTORY);
            $uri = file_unmanaged_copy($source, $data['uri'][0]['value']);
            $data['uri'] = [
              [
                'value' => $uri,
                'url' => file_url_transform_relative(file_create_url($uri))
              ]
            ];

            // We just need one method to create the image.
            $file_data = '';
          }
        }
      }
    }

    $entity = parent::denormalize($data, $class, $format, $serializer_context);

    // If the image was sent as base64 we must to create the physical file.
    if ($file_data) {
      // Decode and save to file.
      $file_contents = base64_decode($file_data);
      $dirname = $this->fileSystem->dirname($entity->getFileUri());
      file_prepare_directory($dirname, FILE_CREATE_DIRECTORY);
      if ($uri = file_unmanaged_save_data($file_contents, $entity->getFileUri())) {
        $entity->setFileUri($uri);
      }
      else {
        throw new \RuntimeException(SafeMarkup::format('Failed to write @filename.', array('@filename' => $entity->getFilename())));
      }
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $serializer_context = array()) {
    $data = parent::normalize($object, $format, $serializer_context);

    // The image will be saved in the export directory.
    if (!empty($serializer_context['content_sync_directory_files'])) {
      $uri = $object->getFileUri();
      $scheme = $this->fileSystem->uriScheme($uri);
      $destination = "{$serializer_context['content_sync_directory_files']}/{$scheme}/";
      $destination = str_replace($scheme . '://', $destination, $uri);
      file_prepare_directory($this->fileSystem->dirname($destination), FILE_CREATE_DIRECTORY);
      file_unmanaged_copy($uri, $destination, FILE_EXISTS_REPLACE);
    }

    // Set base64-encoded file contents to the "data" property.
    if (!empty($serializer_context['content_sync_file_base_64'])) {
      $file_data = base64_encode(file_get_contents($object->getFileUri()));
      $data['data'] = [['value' => $file_data]];
    }

    return $data;
  }

  const COMPARE_READLEN = 4096;
  /**
   * Compare the contents of two files.
   *
   * @param string $alpha
   *   The filename of the first file to compare.
   * @param string $bravo
   *   The filename of the second file to compare.
   *
   * @return boolean
   *   TRUE if the contents appear to be identical; otherwise, FALSE.
   *
   * @see https://www.php.net/manual/en/function.md5-file.php#94494
   */
  protected function compareFiles($alpha, $bravo) {
    if (filesize($alpha) !== filesize($bravo)) {
      // Different filesizes, obviously different content.
      return FALSE;
    }

    if(!$fp1 = fopen($alpha, 'rb')) {
      return FALSE;
    }

    if(!$fp2 = fopen($bravo, 'rb')) {
      fclose($fp1);
      return FALSE;
    }

    $same = TRUE;
    while (!feof($fp1) and !feof($fp2)) {
      if(fread($fp1, static::COMPARE_READLEN) !== fread($fp2, static::COMPARE_READLEN)) {
        $same = FALSE;
        break;
      }
    }

    if(feof($fp1) !== feof($fp2)) {
      $same = FALSE;
    }

    fclose($fp1);
    fclose($fp2);

    return $same;
  }

}
