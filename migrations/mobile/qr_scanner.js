// ============================================================
//  FILE: mobile/qr_scanner.js
//
//  QR Scanner for mobile apps (iOS/Android).
//
//  Uses device camera to scan QR codes for court check-in.
//
//  Dependencies: Requires camera permissions, QR library (e.g., ZXing)
// ============================================================

class QRScanner {
    constructor(videoElement, onScanCallback) {
        this.video = videoElement;
        this.onScan = onScanCallback;
        this.stream = null;
        this.scanning = false;
    }

    async start() {
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' }
            });
            this.video.srcObject = this.stream;
            this.scanning = true;
            this.scanLoop();
        } catch (error) {
            console.error('Camera access failed:', error);
            alert('Camera access required for QR scanning');
        }
    }

    stop() {
        this.scanning = false;
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
        }
    }

    async scanLoop() {
        if (!this.scanning) return;

        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        canvas.width = this.video.videoWidth;
        canvas.height = this.video.videoHeight;
        ctx.drawImage(this.video, 0, 0);

        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);

        // Use QR library to decode (placeholder - integrate ZXing or similar)
        const qrCode = await this.decodeQR(imageData);

        if (qrCode) {
            this.onScan(qrCode);
            this.stop();
        } else {
            requestAnimationFrame(() => this.scanLoop());
        }
    }

    async decodeQR(imageData) {
        // Placeholder: Integrate with QR decoding library
        // For example: return ZXing.decode(imageData);
        return null; // No QR found
    }
}

// Usage example:
/*
const scanner = new QRScanner(
    document.getElementById('qr-video'),
    (qrData) => {
        console.log('Scanned QR:', qrData);
        // Send to server
        fetch('/api/mobile.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + userToken
            },
            body: JSON.stringify({ action: 'scan', qr_data: qrData })
        });
    }
);

scanner.start();
*/