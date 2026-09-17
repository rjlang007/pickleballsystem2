<?php
// ============================================================
//  FILE: admin/_payment_option_fields.php
//  Shared form fields for adding a new payment option.
//  Included by payment_options.php in the "add" panel.
// ============================================================
?>

<style nonce="<?= getCspNonce() ?>">
/* ── Payment Option Form Fields ─────────────────────────────── */
.pof-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.pof-grid .span-2 { grid-column: 1 / -1; }

@media (max-width: 560px) {
    .pof-grid { grid-template-columns: 1fr; }
    .pof-grid .span-2 { grid-column: 1; }
}

.pof-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--muted);
    margin-bottom: 6px;
}
.pof-label .optional {
    font-size: 10px;
    font-weight: 400;
    color: rgba(100,116,139,.6);
    text-transform: none;
    letter-spacing: 0;
    margin-left: 4px;
}

.pof-input {
    width: 100%;
    background: var(--surface2, #0d1f2d);
    border: 1.5px solid var(--border, #1a2a35);
    border-radius: 10px;
    color: var(--text, #f0fdf4);
    font-family: 'DM Sans', inherit;
    font-size: 14px;
    padding: 10px 14px;
    transition: border-color .2s, box-shadow .2s;
    -webkit-appearance: none;
}
.pof-input:focus {
    outline: none;
    border-color: var(--accent, #00e5a0);
    box-shadow: 0 0 0 3px rgba(0,229,160,.08);
}
.pof-input::placeholder { color: var(--muted, #64748b); }

.pof-textarea {
    resize: vertical;
    min-height: 90px;
    line-height: 1.6;
}

/* ── QR Drop Zone ────────────────────────────────────────────── */
.pof-drop-zone {
    position: relative;
    border: 2px dashed var(--border, #1a2a35);
    border-radius: 12px;
    padding: 28px 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    background: var(--surface2, #0d1f2d);
}
.pof-drop-zone:hover,
.pof-drop-zone.drag-over {
    border-color: var(--accent, #00e5a0);
    background: rgba(0,229,160,.04);
}
.pof-drop-zone input[type="file"] { display: none; }

.pof-dz-icon {
    font-size: 34px;
    margin-bottom: 8px;
    display: block;
    line-height: 1;
}
.pof-dz-hint {
    font-size: 13px;
    color: var(--muted, #64748b);
    line-height: 1.5;
}
.pof-dz-hint strong { color: var(--accent, #00e5a0); }

/* Preview shown after file is selected */
.pof-qr-preview {
    display: none;
    margin-top: 14px;
    animation: pofPreviewIn .25s ease;
}
@keyframes pofPreviewIn { from{opacity:0;transform:scale(.94)} to{opacity:1;transform:scale(1)} }
.pof-qr-preview img {
    max-width: 140px;
    max-height: 140px;
    object-fit: contain;
    border-radius: 10px;
    background: #fff;
    padding: 8px;
    display: block;
    margin: 0 auto;
    box-shadow: 0 4px 20px rgba(0,0,0,.4);
}
.pof-qr-preview .pof-preview-name {
    font-size: 11px;
    color: var(--muted, #64748b);
    margin-top: 6px;
}
.pof-qr-preview .pof-remove-btn {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3);
    color: #ef4444;
    border-radius: 6px;
    padding: 4px 12px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 8px;
    font-family: inherit;
    transition: background .15s;
}
.pof-qr-preview .pof-remove-btn:hover { background: rgba(239,68,68,.2); }

/* ── Toggle checkbox row ─────────────────────────────────────── */
.pof-toggle-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    background: var(--surface2, #0d1f2d);
    border: 1.5px solid var(--border, #1a2a35);
    border-radius: 10px;
    cursor: pointer;
    transition: border-color .2s;
}
.pof-toggle-row:hover { border-color: rgba(0,229,160,.35); }
.pof-toggle-row input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--accent, #00e5a0);
    cursor: pointer;
    flex-shrink: 0;
}
.pof-toggle-text {
    font-size: 14px;
    font-weight: 600;
    color: var(--text, #f0fdf4);
    user-select: none;
}
.pof-toggle-text small {
    display: block;
    font-size: 11px;
    font-weight: 400;
    color: var(--muted, #64748b);
    margin-top: 1px;
}
</style>

<div class="pof-grid">

    <!-- Method Name -->
    <div class="span-2">
        <label class="pof-label" for="pof-name">Payment Method Name <span style="color:var(--danger,#ef4444);">*</span></label>
        <input class="pof-input" type="text" id="pof-name" name="opt_name"
               placeholder="e.g. GCash, Maya, BDO Bank Transfer" required
               autocomplete="off"/>
    </div>

    <!-- Account Name -->
    <div>
        <label class="pof-label" for="pof-acct-name">Account Name</label>
        <input class="pof-input" type="text" id="pof-acct-name" name="opt_account_name"
               placeholder="Padol Pickleball Court"
               autocomplete="off"/>
    </div>

    <!-- Account Number / Phone -->
    <div>
        <label class="pof-label" for="pof-acct-no">Account Number / Phone</label>
        <input class="pof-input" type="text" id="pof-acct-no" name="opt_account_no"
               placeholder="0917 123 4567"
               autocomplete="off" inputmode="tel"/>
    </div>

    <!-- Instructions -->
    <div class="span-2">
        <label class="pof-label" for="pof-instructions">
            Instructions <span class="optional">(optional)</span>
        </label>
        <textarea class="pof-input pof-textarea" id="pof-instructions" name="opt_instructions"
                  placeholder="Send the exact amount to the number above, then submit your GCash reference number and a screenshot for verification."></textarea>
    </div>

    <!-- QR Code Upload -->
    <div class="span-2">
        <label class="pof-label">
            QR Code Image <span class="optional">(JPG / PNG / WebP · max 3 MB)</span>
        </label>

        <div class="pof-drop-zone" id="pof-dz"
             onclick="document.getElementById('pof-qr-file').click()"
             ondragover="pofDragOver(event)"
             ondragleave="pofDragLeave(event)"
             ondrop="pofDrop(event)">

            <span class="pof-dz-icon" id="pof-dz-icon">📷</span>
            <div class="pof-dz-hint" id="pof-dz-hint">
                <strong>Click or drag &amp; drop</strong> your QR code here<br/>
                The image will be shown to players on the top-up page
            </div>

            <div class="pof-qr-preview" id="pof-preview">
                <img id="pof-preview-img" src="" alt="QR Preview"/>
                <div class="pof-preview-name" id="pof-preview-name"></div>
                <button type="button" class="pof-remove-btn" onclick="pofRemoveFile(event)">✕ Remove</button>
            </div>

            <input type="file" id="pof-qr-file" name="qr_image"
                   accept="image/jpeg,image/png,image/webp"/>
        </div>
    </div>

    <!-- Active Toggle -->
    <div class="span-2">
        <label class="pof-toggle-row" for="pof-active">
            <input type="checkbox" id="pof-active" name="opt_active" checked/>
            <div class="pof-toggle-text">
                Show immediately on top-up page
                <small>Players will see this payment option right after saving</small>
            </div>
        </label>
    </div>

</div>

<script nonce="<?= getCspNonce() ?>">
(function () {
    'use strict';

    var fileInput = document.getElementById('pof-qr-file');
    if (!fileInput) return;

    fileInput.addEventListener('change', function () {
        handlePofFile(this.files[0]);
    });

    function handlePofFile(file) {
        if (!file) return;
        var maxBytes = 3 * 1024 * 1024;
        if (file.size > maxBytes) {
            alert('File too large. Maximum size is 3 MB.');
            return;
        }
        var allowed = ['image/jpeg','image/png','image/webp'];
        if (!allowed.includes(file.type)) {
            alert('Invalid file type. Please upload JPG, PNG, or WebP.');
            return;
        }
        var reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('pof-preview-img').src     = e.target.result;
            document.getElementById('pof-preview-name').textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
            document.getElementById('pof-dz-icon').style.display  = 'none';
            document.getElementById('pof-dz-hint').style.display  = 'none';
            document.getElementById('pof-preview').style.display  = 'block';
        };
        reader.readAsDataURL(file);
    }

    window.pofDragOver = function (e) {
        e.preventDefault();
        document.getElementById('pof-dz').classList.add('drag-over');
    };
    window.pofDragLeave = function (e) {
        e.preventDefault();
        document.getElementById('pof-dz').classList.remove('drag-over');
    };
    window.pofDrop = function (e) {
        e.preventDefault();
        document.getElementById('pof-dz').classList.remove('drag-over');
        var f = e.dataTransfer.files[0];
        if (f) {
            /* Assign dropped file to the real input via DataTransfer */
            var dt = new DataTransfer();
            dt.items.add(f);
            fileInput.files = dt.files;
            handlePofFile(f);
        }
    };
    window.pofRemoveFile = function (e) {
        e.stopPropagation();
        fileInput.value = '';
        document.getElementById('pof-preview').style.display  = 'none';
        document.getElementById('pof-dz-icon').style.display  = 'block';
        document.getElementById('pof-dz-hint').style.display  = 'block';
        document.getElementById('pof-preview-img').src = '';
    };
}());
</script>