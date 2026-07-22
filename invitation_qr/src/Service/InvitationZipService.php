<?php

namespace Drupal\invitation_qr\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Builds a ZIP archive of all ready stamped invitation cards for a given node.
 *
 * Uses PHP's native ZipArchive (bundled with PHP core — no extra dependency).
 * The ZIP is written to a temp file, streamed to the browser, then deleted.
 */
class InvitationZipService {

  protected FileSystemInterface $fileSystem;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected ConfigFactoryInterface $configFactory;
  protected $logger;

  public function __construct(
    FileSystemInterface $fileSystem,
    EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->fileSystem        = $fileSystem;
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory     = $configFactory;
    $this->logger            = $loggerFactory->get('invitation_qr');
  }

  /**
   * Builds a ZIP of all stamped cards for the given node.
   *
   * @param int   $nodeId      Parent invitation node ID.
   * @param array $submissions Array of WebformSubmissionInterface objects.
   *
   * @return string|null  Real filesystem path to the temp ZIP, or NULL if empty.
   */
  public function buildZip(int $nodeId, array $submissions): ?string {
    if (!class_exists('\ZipArchive')) {
      throw new \RuntimeException('PHP ZipArchive extension is not available.');
    }

    $zip     = new \ZipArchive();
    $tmpPath = $this->fileSystem->tempnam(
      $this->fileSystem->realpath('public://'),
      'invitation_qr_zip_'
    );

    if ($zip->open($tmpPath, \ZipArchive::OVERWRITE) !== TRUE) {
      throw new \RuntimeException("Cannot create ZIP archive at $tmpPath.");
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');
    $added       = 0;

    foreach ($submissions as $submission) {
      $data = $submission->getData();
      $fid  = $data['stamped_card_fid'] ?? NULL;

      if (!$fid) {
        // Not yet processed — skip.
        continue;
      }

      /** @var \Drupal\file\FileInterface|null $file */
      $file = $fileStorage->load($fid);
      if (!$file) {
        continue;
      }

      $realPath = $this->fileSystem->realpath($file->getFileUri());
      if (!$realPath || !file_exists($realPath)) {
        continue;
      }

      // Name each file inside the ZIP after the guest for easy identification.
      $name      = $data['name'] ?? 'guest';
      $phone     = $data['phone_number'] ?? $submission->id();
      $safeName  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
      $safePhone = preg_replace('/[^0-9]/', '', $phone);
      $filename  = "invitation_{$safeName}_{$safePhone}.png";

      $zip->addFile($realPath, $filename);
      $added++;
    }

    $zip->close();

    if ($added === 0) {
      @unlink($tmpPath);
      return NULL;
    }

    $this->logger->info(
      'Built ZIP with @count stamped cards for node @nid.',
      ['@count' => $added, '@nid' => $nodeId]
    );

    return $tmpPath;
  }


  /**
   * Builds a ZIP of all stamped ACCESS cards for a node.
   */
  public function buildAccessZip(int $nodeId, array $submissions): ?string {
    if (!class_exists('\ZipArchive')) {
      throw new \RuntimeException('PHP ZipArchive extension is required for ZIP downloads.');
    }

    $files = [];
    foreach ($submissions as $submission) {
      $data = $submission->getData();
      $fid  = $data['access_card_fid'] ?? NULL;
      if (!$fid) continue;

      $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
      if (!$file) continue;

      $realPath = \Drupal::service('file_system')->realpath($file->getFileUri());
      if (!$realPath || !file_exists($realPath)) continue;

      $name  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['name'] ?? 'guest');
      $phone = preg_replace('/[^0-9]/', '', $data['phone_number'] ?? $submission->id());
      $files[] = ['path' => $realPath, 'name' => "access_{$name}_{$phone}.png"];
    }

    if (empty($files)) {
      return NULL;
    }

    $tmpPath = \Drupal::service('file_system')->getTempDirectory()
      . '/access_cards_' . $nodeId . '_' . time() . '.zip';

    $zip = new \ZipArchive();
    if ($zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
      throw new \RuntimeException("Cannot create ZIP file at $tmpPath");
    }

    foreach ($files as $f) {
      $zip->addFile($f['path'], $f['name']);
    }
    $zip->close();

    return $tmpPath;
  }

}
