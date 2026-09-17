<?php
/**
 * FILE: includes/modal-confirm.php
 * 
 * Confirmation modal for destructive actions
 * Usage: confirmAction('Cancel Reservation', 'Are you sure? You will lose your court slot.', '/api/cancel-reservation', 'DELETE')
 */

function renderConfirmModal() {
    ?>
    <div id="confirm-modal" class="confirm-modal" style="display: none;" role="dialog" aria-labelledby="confirm-title" aria-hidden="true">
        <div class="confirm-overlay" onclick="closeConfirmModal()"></div>
        <div class="confirm-box">
            <div class="confirm-header">
                <h2 id="confirm-title" class="confirm-title">Confirm Action</h2>
                <button class="confirm-close" onclick="closeConfirmModal()" aria-label="Close">✕</button>
            </div>
            <div class="confirm-body">
                <p id="confirm-message" class="confirm-message"></p>
            </div>
            <div class="confirm-footer">
                <button class="btn-outline" onclick="closeConfirmModal()">Cancel</button>
                <button id="confirm-submit" class="btn-danger" onclick="submitConfirmAction()">Confirm</button>
            </div>
        </div>
    </div>

    <script nonce="<?= getCspNonce() ?>">
    let confirmAction = {
        url: null,
        method: 'POST',
        callback: null
    };

    function openConfirmModal(title, message, url, method = 'POST', callback = null) {
        document.getElementById('confirm-title').textContent = title;
        document.getElementById('confirm-message').textContent = message;
        document.getElementById('confirm-modal').style.display = 'flex';
        document.getElementById('confirm-modal').setAttribute('aria-hidden', 'false');
        confirmAction = { url, method, callback };
        document.getElementById('confirm-submit').focus();
    }

    function closeConfirmModal() {
        document.getElementById('confirm-modal').style.display = 'none';
        document.getElementById('confirm-modal').setAttribute('aria-hidden', 'true');
        confirmAction = { url: null, method: 'POST', callback: null };
    }

    async function submitConfirmAction() {
        const btn = document.getElementById('confirm-submit');
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Processing...';
        
        try {
            const response = await fetch(confirmAction.url, {
                method: confirmAction.method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.ok) {
                showToast({ type: 'success', message: data.message || 'Action completed successfully' });
                closeConfirmModal();
                
                if (typeof confirmAction.callback === 'function') {
                    confirmAction.callback(data);
                } else if (data.redirect) {
                    setTimeout(() => window.location.href = data.redirect, 500);
                }
            } else {
                showToast({ type: 'error', message: data.error?.message || 'Action failed' });
            }
        } catch (err) {
            showToast({ type: 'error', message: 'Network error: ' + err.message });
            btn.disabled = false;
            btn.textContent = originalText;
        }
    }

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeConfirmModal();
    });
    </script>

    <style nonce="<?= getCspNonce() ?>">
    .confirm-modal {
        display: none !important;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 5000;
        align-items: center;
        justify-content: center;
        padding: 16px;
        box-sizing: border-box;
    }

    .confirm-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        cursor: pointer;
        z-index: -1;
    }

    .confirm-box {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 0;
        max-width: 420px;
        width: 100%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        z-index: 1;
        animation: modalSlideIn 0.3s ease-out;
    }

    .confirm-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 24px;
        border-bottom: 1px solid var(--border);
    }

    .confirm-title {
        font-size: 18px;
        margin: 0;
        font-weight: 600;
        color: var(--text);
    }

    .confirm-close {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 20px;
        color: var(--muted);
        padding: 4px 8px;
        transition: color 0.2s;
        -webkit-appearance: none;
    }

    .confirm-close:hover {
        color: var(--text);
    }

    .confirm-body {
        padding: 20px 24px;
    }

    .confirm-message {
        font-size: 14px;
        line-height: 1.6;
        color: var(--text-secondary);
        margin: 0;
    }

    .confirm-footer {
        display: flex;
        gap: 12px;
        padding: 16px 24px;
        border-top: 1px solid var(--border);
        justify-content: flex-end;
    }

    .confirm-footer .btn-outline,
    .confirm-footer .btn-danger {
        flex: 1;
    }

    .btn-danger {
        background: #ef4444;
        color: white;
        border: none;
        padding: 11px 18px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
        -webkit-appearance: none;
    }

    .btn-danger:hover {
        background: #dc2626;
    }

    .btn-danger:disabled {
        background: #9ca3af;
        cursor: not-allowed;
    }

    @keyframes modalSlideIn {
        from {
            transform: scale(0.95);
            opacity: 0;
        }
        to {
            transform: scale(1);
            opacity: 1;
        }
    }

    @media (max-width: 576px) {
        .confirm-box {
            max-width: 100%;
        }
    }
    </style>
    <?php
}
