<?php
/**
 * FILE: includes/toast.php
 * 
 * Toast notification system for celebratory/informative messages
 * Usage in page: toastFlash('success', '✅ Reservation confirmed!')
 * Or via JS: showToast({type: 'success', message: '...'})
 */

function toastFlash($type = 'info', $message = '') {
    $_SESSION['_toast_queue'] = $_SESSION['_toast_queue'] ?? [];
    $_SESSION['_toast_queue'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function getToastQueue() {
    $queue = $_SESSION['_toast_queue'] ?? [];
    unset($_SESSION['_toast_queue']);
    return $queue;
}

function renderToastContainer() {
    $queue = getToastQueue();
    $queueJson = htmlspecialchars(json_encode($queue), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    
    ob_start();
    ?>
    <div id="toast-container" class="toast-container" role="region" aria-live="polite" aria-atomic="true"></div>

    <script nonce="<?= getCspNonce() ?>">
    const toastQueue = <?= $queueJson ?>;
    
    function showToast(opts = {}) {
        const { type = 'info', message = '', duration = 4000 } = opts;
        const container = document.getElementById('toast-container');
        const toastId = 'toast-' + Date.now();
        
        const icons = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };
        
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.id = toastId;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="toast-icon">${icons[type] || '📢'}</div>
            <div class="toast-message">${message}</div>
            <button class="toast-close" onclick="this.parentElement.remove()">✕</button>
        `;
        
        container.appendChild(toast);
        
        // Auto-remove after duration
        const timer = setTimeout(() => {
            const el = document.getElementById(toastId);
            if (el) el.remove();
        }, duration);
        
        // Manual close cancels timer
        toast.querySelector('.toast-close').onclick = function() {
            clearTimeout(timer);
            toast.remove();
        };
    }
    
    // Show queued toasts on page load
    toastQueue.forEach((t, i) => {
        setTimeout(() => showToast(t), i * 400);
    });
    
    // Expose globally for API responses
    window.showToast = showToast;
    </script>

    <style nonce="<?= getCspNonce() ?>">
    .toast-container {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 12px;
        pointer-events: none;
    }

    .toast {
        display: flex;
        align-items: center;
        gap: 12px;
        background: var(--surface);
        border: 2px solid var(--border);
        border-radius: 12px;
        padding: 14px 18px;
        min-width: 300px;
        max-width: 450px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
        pointer-events: auto;
        animation: slideIn 0.3s ease-out;
    }

    .toast-icon {
        font-size: 20px;
        min-width: 24px;
        text-align: center;
    }

    .toast-message {
        flex: 1;
        font-size: 14px;
        line-height: 1.4;
        color: var(--text);
    }

    .toast-close {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--muted);
        font-size: 16px;
        padding: 2px 4px;
        transition: color 0.2s;
        -webkit-appearance: none;
    }

    .toast-close:hover {
        color: var(--text);
    }

    /* Type-specific styling */
    .toast-success {
        border-color: #10b981;
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.05));
    }

    .toast-error {
        border-color: #ef4444;
        background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.05));
    }

    .toast-warning {
        border-color: #f59e0b;
        background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.05));
    }

    .toast-info {
        border-color: #3b82f6;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(37, 99, 235, 0.05));
    }

    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @media (max-width: 576px) {
        .toast-container {
            bottom: 16px;
            right: 16px;
            left: 16px;
        }

        .toast {
            min-width: auto;
            max-width: 100%;
        }
    }
    </style>
    <?php
    return ob_get_clean();
}
