<?php

namespace Drupal\invitation_qr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\invitation_qr\Service\InvitationQrService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a throw-away access card preview for the Event Card Settings page.
 *
 * Stamps the node's access card design with a sample QR and the first
 * guest's name (or a sample name) using the saved per-event settings.
 * Nothing is written to the guest's submission and no file entity is
 * created — the temporary QR is deleted straight after.
 */
class AccessCardPreviewController extends ControllerBase {

  protected InvitationQrService $qrService;
  protected FileSystemInterface $fileSystem;

  public function __construct(InvitationQrService $qrService, FileSystemInterface $fileSystem) {
    $this->qrService  = $qrService;
    $this->fileSystem = $fileSystem;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('invitation_qr.qr_service'),
      $container->get('file_system')
    );
  }

  public function preview(NodeInterface $node): Response {
    $config     = $this->config('invitation_qr.settings');
    $fieldName  = $config->get('access_card_field') ?: 'field_access_card';

    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty() || !$node->get($fieldName)->entity) {
      return $this->placeholder('This event has no access card image yet. Upload one on the event first.');
    }
    $cardUri = $node->get($fieldName)->entity->getFileUri();

    // Sample guest data: first real guest if there is one.
    $data = ['name' => 'Oluseyi Adelegan'];
    $subs = $this->qrService->getSubmissionsForNode((int) $node->id());
    foreach ($subs as $sub) {
      $d = $sub->getData();
      if (!empty($d['name'])) { $data = $d; break; }
    }

    $nid = (int) $node->id();
    [$lines, $nameOptions] = $this->qrService->accessNameArgs($nid, $data, $config);
    [$qrPos, $qrMargin, $qrOffX, $qrOffY, $qrSize] = $this->qrService->accessQrArgs($nid, $config);

    $qrUri = 'temporary://iqr-preview-' . $nid . '-' . bin2hex(random_bytes(4)) . '.png';
    try {
      file_put_contents($qrUri, $this->qrService->generateQrPng(
        \Drupal::request()->getSchemeAndHttpHost() . '/verify-guest?token=PREVIEW',
        $qrSize
      ));
      $png = $this->qrService->stampAccessCard($cardUri, $qrUri, $qrPos, $qrMargin, $qrOffX, $qrOffY, $lines, $nameOptions);
    }
    catch (\Throwable $e) {
      return $this->placeholder('Preview failed: ' . $e->getMessage());
    }
    finally {
      $tmp = $this->fileSystem->realpath($qrUri);
      if ($tmp && is_file($tmp)) @unlink($tmp);
    }

    return new Response($png, 200, [
      'Content-Type'  => 'image/png',
      'Cache-Control' => 'no-store, max-age=0',
    ]);
  }

  /** Small PNG with a message, so the <img> on the form never shows broken. */
  protected function placeholder(string $message): Response {
    $img = imagecreatetruecolor(900, 120);
    imagefill($img, 0, 0, imagecolorallocate($img, 250, 250, 250));
    $black = imagecolorallocate($img, 60, 60, 60);
    foreach (str_split($message, 100) as $i => $chunk) {
      imagestring($img, 4, 12, 20 + $i * 22, $chunk, $black);
    }
    ob_start();
    imagepng($img);
    imagedestroy($img);
    return new Response(ob_get_clean(), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
  }

}
