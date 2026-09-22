// ============================================================
//  FILE: mobile/qr_scanner.js  — RETIRED
//
//  Camera-based QR scanner used by the mobile shell to check
//  players in at a court. Scanner check-in has been removed:
//  players join the live Open Play queue, or book under Court
//  Reservations.
//
//  Kept as an inert shim so older bundles that still <script>
//  this file don't throw a ReferenceError on `new QRScanner(...)`
//  and take the whole page down with them. Every method is a
//  no-op that reports the feature as unavailable.
// ============================================================

class QRScanner {
    constructor() {
        console.warn('[QRScanner] Retired: QR check-in has been replaced by the Open Play queue.');
        this.scanning = false;
        this.retired  = true;
    }

    async start() {
        return Promise.reject(new Error('QR check-in has been retired. Join the Open Play queue instead.'));
    }

    stop()      { /* no-op */ }
    scanLoop()  { /* no-op */ }
    isAvailable() { return false; }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = QRScanner;
}
