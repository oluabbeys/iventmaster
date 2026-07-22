# Invitation QR — Drupal 10 Custom Module (v2)

## Architecture

```
Invitation Node
 └── field_invitation_card        ← master card template (uploaded once per event)

Webform Submission (one per guest — unlimited)
 ├── name, email, phone_number    ← guest data entered by editor
 ├── guest_token    (hidden)      ← 12-char unique token, auto-generated
 └── stamped_card_fid (hidden)    ← managed file ID of the QR-stamped card

Queue: invitation_qr_stamping
 └── one job per submission → processed by cron / drush off-request

/verify-guest?token=XXXX          ← public page scanned at the event
/admin/invitation-qr/submissions/{nid}  ← per-event management page
```

**Key principle:** The node is only a template. Every guest submission owns its
own stamped card (stored as a managed file, referenced by FID in submission data).
The node field never accumulates per-guest files.

---

## Why Queue-based?

| Scenario | Real-time | Queue |
|---|---|---|
| 10 guests | ✅ Fine | ✅ Fine |
| 500 guests (CSV import) | ❌ PHP timeout | ✅ Processes in background |
| Server under load | ❌ Slow responses | ✅ Non-blocking |
| Failed stamping | ❌ Lost | ✅ Retried on next cron |

---

## Requirements

- Drupal 10
- PHP 8.1+ with GD extension (PNG support)
- PHP ZipArchive extension (bundled with PHP)
- Webform module
- `endroid/qr-code` Composer package

---

## Installation

### 1. Install QR library
```bash
composer require endroid/qr-code
```

### 2. Place the module
```
web/modules/custom/invitation_qr/
```

### 3. Enable
```bash
drush en invitation_qr -y
drush cr
```

### 4. Add hidden fields to your Webform

In the Webform UI, add two **Hidden** elements:

| Key | Label |
|---|---|
| `guest_token` | Guest Token |
| `stamped_card_fid` | Stamped Card FID |

### 5. Add the card image field to your node type

| Field Label | Machine Name | Type |
|---|---|---|
| Invitation Card | `field_invitation_card` | Image |

> ⚠️ Do NOT add `field_invitation_stamped_card` to the node anymore.
> Stamped cards now live on each submission.

### 6. Configure the module

Go to `/admin/config/invitation-qr/settings` and set your webform ID,
content type, field names, and QR stamp position/size.

---

## Editor Workflow

1. Create an **Invitation** node → upload the card template image
2. Embed the webform on the node (use the Webform field or block)
3. Guests/editors fill and submit the webform (one per guest)
4. Each submission is queued for QR stamping
5. Run `drush queue:run invitation_qr_stamping` or wait for cron
6. Go to `/admin/invitation-qr/submissions/{node-id}` to:
   - See all guests and their QR status (Pending / ✅ Ready)
   - Download individual stamped cards
   - Download a **ZIP** of all stamped cards at once

---

## Bulk Processing

After a large CSV import or many submissions:

```bash
# Check queue size
drush queue:list

# Process all queued jobs immediately
drush queue:run invitation_qr_stamping

# Or process in time-limited chunks (useful on shared hosting)
drush queue:run invitation_qr_stamping --time-limit=60
```

---

## QR Scan at the Event

Print or share each guest's stamped card. When staff scan the QR code,
it opens:

```
https://yoursite.com/verify-guest?token=ABC123DEF456
```

No login required. Shows guest name, phone, and email instantly.

---

## Supported Card Image Formats

- JPEG / JPG
- PNG
- WebP

---

## Troubleshooting

| Problem | Solution |
|---|---|
| QR not stamped | Check `admin/reports/dblog` for `invitation_qr` errors |
| Queue not processing | Run `drush queue:run invitation_qr_stamping` |
| GD error | Confirm `php -m \| grep gd` — GD must be enabled |
| ZIP empty | Ensure jobs have run and submissions show ✅ Ready |
| Token not found | Confirm `guest_token` hidden field exists on webform |
| Wrong node linked | Ensure webform is embedded via a Webform field on the node (sets source entity) |
