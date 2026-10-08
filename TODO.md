# DoorKing TODO

- [ ] **1. The export ignores credential expiry.** `class-fsbhoa-doorking-export.php` selects credentials by status only, never `expiration_date`. Nothing sets a real DoorKing expiry today (the importer and PIN rotation use 2099-12-31, others are blank), so it does no harm yet. If expiry dates are used later, skip credentials whose `expiration_date` is before today (valid through the expiry date, like the UHPPOTE controllers and the kiosk). An expired credential would only drop out at the next export.
