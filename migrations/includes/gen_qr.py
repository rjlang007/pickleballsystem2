#!/usr/bin/env python3
"""
QR code generator — called from PHP via shell_exec().
Usage: python3 gen_qr.py <data>

WINDOWS STDOUT FIX:
Writing binary PNG to stdout is unreliable on Windows because PHP's
shell_exec() pipes stdout in text mode, corrupting binary data.
Instead this script writes PNG to a temp file and prints the path.
PHP reads the file directly. Temp file is deleted by PHP after reading.

Install once:
    pip install qrcode[pil]
"""
import sys
import io
import tempfile

def main():
    if len(sys.argv) < 2 or not sys.argv[1].strip():
        sys.stderr.write("Error: no data argument\n")
        sys.exit(1)

    data = sys.argv[1]

    try:
        import qrcode
        from qrcode.constants import ERROR_CORRECT_L

        qr = qrcode.QRCode(
            version=None,
            error_correction=ERROR_CORRECT_L,
            box_size=12,
            border=4,
        )
        qr.add_data(data.encode('latin-1'))
        qr.make(fit=True)

        img = qr.make_image(fill_color="black", back_color="white")
        buf = io.BytesIO()
        img.save(buf, format='PNG')
        png_bytes = buf.getvalue()

        tmp = tempfile.NamedTemporaryFile(suffix='.png', delete=False, mode='wb')
        tmp.write(png_bytes)
        tmp.close()
        print(tmp.name)

    except ImportError:
        try:
            import qrcode
            from qrcode.constants import ERROR_CORRECT_L
            from qrcode.image.pure import PyPNGImage

            qr = qrcode.QRCode(version=None, error_correction=ERROR_CORRECT_L, box_size=12, border=4)
            qr.add_data(data.encode('latin-1'))
            qr.make(fit=True)
            img = qr.make_image(image_factory=PyPNGImage)
            buf = io.BytesIO()
            img.save(buf)
            png_bytes = buf.getvalue()

            tmp = tempfile.NamedTemporaryFile(suffix='.png', delete=False, mode='wb')
            tmp.write(png_bytes)
            tmp.close()
            print(tmp.name)

        except Exception as e:
            sys.stderr.write(f"Error: {e}\n")
            sys.exit(1)

    except Exception as e:
        sys.stderr.write(f"Error: {e}\n")
        sys.exit(1)

if __name__ == '__main__':
    main()