<?php
/**
 * FILE: includes/breadcrumb.php
 * 
 * Global breadcrumb navigation component
 * Usage: renderBreadcrumb(['Home' => '/pickleball/', 'Players' => '/pickleball/admin/players.php', 'Add Player'])
 * Last item (no URL) is marked as current
 */

function renderBreadcrumb($crumbs = []) {
    if (empty($crumbs)) return '';
    
    // Normalize: handle both formats
    // Format 1: ['label' => 'Home', 'href' => '/path']
    // Format 2: [['label' => 'Home', 'href' => '/path'], ['label' => 'Page']]
    $normalized = [];
    foreach ($crumbs as $item) {
        if (is_array($item) && isset($item['label'])) {
            // Format 2: array with 'label' key
            $normalized[] = [
                'label' => $item['label'] ?? '',
                'href'  => $item['href'] ?? null
            ];
        } elseif (is_string($item)) {
            // Single label string (no link)
            $normalized[] = ['label' => $item, 'href' => null];
        }
    }
    
    ob_start();
    ?>
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb-list">
            <?php foreach ($normalized as $crumb): ?>
                <li class="breadcrumb-item">
                    <?php if (!empty($crumb['href'])): ?>
                        <a href="<?= clean($crumb['href']) ?>" class="breadcrumb-link">
                            <?= clean($crumb['label']) ?>
                        </a>
                        <span class="breadcrumb-sep">/</span>
                    <?php else: ?>
                        <span class="breadcrumb-current" aria-current="page">
                            <?= clean($crumb['label']) ?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <style nonce="<?= getCspNonce() ?>">
    .breadcrumb {
        font-size: 13px;
        color: var(--muted);
        margin-bottom: 20px;
        padding: 8px 12px;
        background: rgba(0, 229, 160, 0.03);
        border-radius: 8px;
        overflow-x: auto;
    }

    .breadcrumb-list {
        display: flex;
        align-items: center;
        list-style: none;
        margin: 0;
        padding: 0;
        gap: 4px;
    }

    .breadcrumb-item {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .breadcrumb-link {
        color: var(--accent);
        text-decoration: none;
        transition: color 0.2s;
    }

    .breadcrumb-link:hover {
        color: var(--text);
        text-decoration: underline;
    }

    .breadcrumb-current {
        color: var(--text);
        font-weight: 600;
    }

    .breadcrumb-sep {
        color: var(--muted);
    }

    @media (max-width: 576px) {
        .breadcrumb {
            font-size: 12px;
            padding: 6px 8px;
            margin-bottom: 16px;
        }
    }
    </style>
    <?php
    return ob_get_clean();
}
