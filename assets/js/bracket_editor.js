/**
 * Bracket Editor - Admin interface for seeding tournaments
 * File: assets/js/bracket_editor.js
 */

class BracketEditor {
    constructor(containerId, tournamentId) {
        this.container = document.getElementById(containerId);
        this.tournamentId = tournamentId;
        this.seeds = [];
        this.draggedItem = null;
        this.init();
    }

    async init() {
        await this.loadSeeds();
        this.render();
        this.attachEventListeners();
    }

    async loadSeeds() {
        try {
            const response = await fetch(
                `/api/bracket_edit.php?tournament_id=${this.tournamentId}`
            );
            const result = await response.json();
            
            if (result.success || result.data) {
                this.seeds = (result.data || result).seeds || [];
            } else {
                this.showError('Failed to load seeds');
            }
        } catch (error) {
            this.showError('Error loading seeds: ' + error.message);
        }
    }

    render() {
        // NOTE: this used to render its own "Tournament Bracket Editor / Drag to
        // reorder..." heading here, stacked directly under the page's real
        // heading (which already shows the tournament name + player count) —
        // two headers back to back for the same widget. Dropped the duplicate;
        // the instructional copy now lives once, in the page's static header.
        const html = `
            <div class="editor-seeds-container" id="seedsContainer">
                ${this.renderSeeds()}
            </div>

            <div class="editor-actions">
                <button class="editor-btn editor-btn-primary" id="randomizeBtn">
                    🎲 Randomize Seeds
                </button>
                <button class="editor-btn editor-btn-secondary" id="autoSeedBtn">
                    📊 Auto-Seed by Rating
                </button>
                <button class="editor-btn editor-btn-success" id="saveBtn">
                    ✅ Save Changes
                </button>
                <button class="editor-btn editor-btn-danger" id="cancelBtn">
                    ❌ Cancel
                </button>
            </div>

            <div id="feedback" class="editor-feedback"></div>
        `;

        this.container.innerHTML = html;
    }

    renderSeeds() {
        return this.seeds.map((seed, index) => `
            <div class="seed-card" draggable="true" data-seed="${seed.id}" data-index="${index}">
                <div class="seed-number">#${seed.seed}</div>
                <div class="seed-info">
                    ${seed.avatar_url ? `<img src="${seed.avatar_url}" class="seed-avatar" alt="${seed.name}">` : ''}
                    <div class="seed-name">${seed.name}</div>
                </div>
                <div class="seed-handle">⋮⋮</div>
            </div>
        `).join('');
    }

    attachEventListeners() {
        // Randomize button
        document.getElementById('randomizeBtn').addEventListener('click', () => {
            this.randomizeSeeds();
        });

        // Auto-seed by rating button
        document.getElementById('autoSeedBtn').addEventListener('click', () => {
            this.autoSeedByRating();
        });

        // Save button
        document.getElementById('saveBtn').addEventListener('click', () => {
            this.saveChanges();
        });

        // Cancel button
        document.getElementById('cancelBtn').addEventListener('click', () => {
            window.history.back();
        });

        // Drag and drop
        this.attachDragAndDrop();
    }

    attachDragAndDrop() {
        const container = document.getElementById('seedsContainer');
        
        container.addEventListener('dragstart', (e) => {
            if (e.target.classList.contains('seed-card')) {
                this.draggedItem = e.target;
                e.target.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            }
        });

        container.addEventListener('dragend', (e) => {
            if (e.target.classList.contains('seed-card')) {
                e.target.classList.remove('dragging');
                this.draggedItem = null;
            }
        });

        container.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            
            if (e.target.classList.contains('seed-card') && e.target !== this.draggedItem) {
                e.target.classList.add('drag-over');
            }
        });

        container.addEventListener('dragleave', (e) => {
            if (e.target.classList.contains('seed-card')) {
                e.target.classList.remove('drag-over');
            }
        });

        container.addEventListener('drop', (e) => {
            e.preventDefault();
            
            if (e.target.classList.contains('seed-card') && e.target !== this.draggedItem) {
                e.target.classList.remove('drag-over');
                
                // Swap seeds
                const draggedIndex = parseInt(this.draggedItem.dataset.index);
                const targetIndex = parseInt(e.target.dataset.index);
                
                [this.seeds[draggedIndex], this.seeds[targetIndex]] = 
                    [this.seeds[targetIndex], this.seeds[draggedIndex]];
                
                // Re-render
                document.getElementById('seedsContainer').innerHTML = this.renderSeeds();
                this.attachDragAndDrop();
            }
        });
    }

    randomizeSeeds() {
        // Shuffle seeds array
        for (let i = this.seeds.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [this.seeds[i], this.seeds[j]] = [this.seeds[j], this.seeds[i]];
        }
        
        document.getElementById('seedsContainer').innerHTML = this.renderSeeds();
        this.attachDragAndDrop();
        this.showFeedback('Seeds randomized!', 'info');
    }

    async autoSeedByRating() {
        try {
            const response = await fetch(
                `/api/bracket_edit.php?tournament_id=${this.tournamentId}`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'auto_seed_by_rating' })
                }
            );
            
            const result = await response.json();
            if (response.ok || result.success) {
                await this.loadSeeds();
                document.getElementById('seedsContainer').innerHTML = this.renderSeeds();
                this.attachDragAndDrop();
                this.showFeedback('Seeds auto-assigned by rating!', 'success');
            } else {
                this.showError(result.error || 'Failed to auto-seed');
            }
        } catch (error) {
            this.showError('Error auto-seeding: ' + error.message);
        }
    }

    async saveChanges() {
        try {
            // Build new order with updated seeds
            const newOrder = this.seeds.map((seed, index) => ({
                player_id: seed.player_id,
                new_seed: index + 1
            }));

            const response = await fetch(
                `/api/bracket_edit.php?tournament_id=${this.tournamentId}`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'reorder_seeds',
                        new_order: newOrder
                    })
                }
            );

            const result = await response.json();
            
            if (response.ok || result.success) {
                this.showFeedback('✅ Changes saved successfully!', 'success');
                setTimeout(() => {
                    window.location.href = `/admin/tournament_admin.php?tab=bracket&id=${this.tournamentId}`;
                }, 1500);
            } else {
                this.showError(result.error || 'Failed to save changes');
            }
        } catch (error) {
            this.showError('Error saving: ' + error.message);
        }
    }

    showFeedback(message, type = 'info') {
        const feedback = document.getElementById('feedback');
        feedback.className = `editor-feedback editor-feedback-${type}`;
        feedback.textContent = message;
        feedback.style.display = 'block';

        if (type !== 'error') {
            setTimeout(() => {
                feedback.style.display = 'none';
            }, 3000);
        }
    }

    showError(message) {
        this.showFeedback(message, 'error');
    }
}

// Auto-initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('bracketEditorContainer');
    const tournamentId = document.body.dataset.tournamentId || 
                         new URLSearchParams(window.location.search).get('id');
    
    if (container && tournamentId) {
        window.bracketEditor = new BracketEditor('bracketEditorContainer', tournamentId);
    }
});