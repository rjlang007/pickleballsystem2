/**
 * Leaderboard Monthly - Monthly leaderboard snapshots and comparison
 * File: assets/js/leaderboard_monthly.js
 */

class MonthlyLeaderboard {
    constructor(containerId = 'leaderboardMonthlyContainer') {
        this.container = document.getElementById(containerId);
        this.currentMonth = new Date();
        this.selectedMonths = [this.currentMonth];
        this.comparisonMode = false;
        this.init();
    }

    async init() {
        this.renderDateSelector();
        await this.loadLeaderboard();
    }

    renderDateSelector() {
        const selector = document.createElement('div');
        selector.className = 'month-selector';
        
        const prevBtn = document.createElement('button');
        prevBtn.className = 'month-selector-button';
        prevBtn.textContent = '← Previous Month';
        prevBtn.addEventListener('click', () => this.previousMonth());

        const display = document.createElement('div');
        display.className = 'month-selector-display';
        display.id = 'monthDisplay';
        display.textContent = this.formatMonth(this.currentMonth);

        const nextBtn = document.createElement('button');
        nextBtn.className = 'month-selector-button';
        nextBtn.textContent = 'Next Month →';
        nextBtn.addEventListener('click', () => this.nextMonth());

        const comparisonToggle = document.createElement('button');
        comparisonToggle.className = 'month-selector-button';
        comparisonToggle.textContent = '📊 Compare Months';
        comparisonToggle.addEventListener('click', () => this.toggleComparisonMode());

        selector.appendChild(prevBtn);
        selector.appendChild(display);
        selector.appendChild(nextBtn);
        selector.appendChild(comparisonToggle);

        this.container.insertBefore(selector, this.container.firstChild);
    }

    async previousMonth() {
        this.currentMonth = new Date(this.currentMonth.getFullYear(), this.currentMonth.getMonth() - 1, 1);
        document.getElementById('monthDisplay').textContent = this.formatMonth(this.currentMonth);
        await this.loadLeaderboard();
    }

    async nextMonth() {
        this.currentMonth = new Date(this.currentMonth.getFullYear(), this.currentMonth.getMonth() + 1, 1);
        document.getElementById('monthDisplay').textContent = this.formatMonth(this.currentMonth);
        await this.loadLeaderboard();
    }

    formatMonth(date) {
        return date.toLocaleString('default', { month: 'long', year: 'numeric' });
    }

    async loadLeaderboard() {
        try {
            const year = this.currentMonth.getFullYear();
            const month = String(this.currentMonth.getMonth() + 1).padStart(2, '0');
            
            const response = await fetch(
                `/api/leaderboard.php?year=${year}&month=${month}`
            );
            const result = await response.json();
            
            if (result.success || result.data) {
                const data = result.data || result;
                this.renderLeaderboard(data);
                this.calculateStatistics(data);
                this.identifyTopPerformers(data);
            }
        } catch (error) {
            console.error('Error loading leaderboard:', error);
            this.showError('Failed to load leaderboard');
        }
    }

    renderLeaderboard(data) {
        const snapshot = document.getElementById('leaderboardSnapshot') || 
                        this.createSnapshotContainer();

        const rows = (data.rankings || data).map((player, idx) => `
            <tr>
                <td class="player-rank-cell rank-${idx + 1}">${idx + 1}</td>
                <td class="player-name-cell">
                    ${player.avatar_url ? `<img src="${player.avatar_url}" class="player-avatar-cell" alt="">` : ''}
                    ${player.name}
                </td>
                <td class="player-points-cell">${player.points} pts</td>
                <td class="player-wins-cell">${player.wins || 0}</td>
                <td class="player-placement-cell">
                    ${player.avg_placement ? (Number(player.avg_placement).toFixed(1)) : '-'}
                </td>
            </tr>
        `).join('');

        const html = `
            <div class="leaderboard-snapshot">
                <div class="leaderboard-snapshot-header">
                    <div>
                        <h3 class="leaderboard-snapshot-title">${this.formatMonth(this.currentMonth)}</h3>
                        <p class="leaderboard-snapshot-date">Ranking snapshot</p>
                    </div>
                    <div class="leaderboard-snapshot-actions">
                        <button class="snapshot-action-button primary" onclick="this.exportLeaderboard()">
                            📥 Export CSV
                        </button>
                    </div>
                </div>
                <table class="snapshot-leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Player</th>
                            <th>Points</th>
                            <th>Wins</th>
                            <th>Avg Placement</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </div>
        `;

        snapshot.innerHTML = html;
    }

    createSnapshotContainer() {
        const container = document.createElement('div');
        container.id = 'leaderboardSnapshot';
        this.container.appendChild(container);
        return container;
    }

    calculateStatistics(data) {
        const rankings = data.rankings || data;
        const stats = {
            totalPlayers: rankings.length,
            totalTournaments: rankings.reduce((sum, p) => sum + (p.tournament_count || 0), 0),
            avgPoints: (rankings.reduce((sum, p) => sum + p.points, 0) / rankings.length).toFixed(0)
        };

        const statsHtml = `
            <div class="month-statistics">
                <div class="stat-box players">
                    <div class="stat-box-label">Total Players</div>
                    <div class="stat-box-value">${stats.totalPlayers}</div>
                </div>
                <div class="stat-box tournaments">
                    <div class="stat-box-label">Tournaments</div>
                    <div class="stat-box-value">${stats.totalTournaments}</div>
                </div>
                <div class="stat-box avg-placement">
                    <div class="stat-box-label">Avg Points</div>
                    <div class="stat-box-value">${stats.avgPoints}</div>
                </div>
            </div>
        `;

        let statsContainer = document.getElementById('statisticsContainer');
        if (!statsContainer) {
            statsContainer = document.createElement('div');
            statsContainer.id = 'statisticsContainer';
            this.container.insertBefore(statsContainer, this.container.firstChild.nextSibling);
        }
        statsContainer.innerHTML = statsHtml;
    }

    identifyTopPerformers(data) {
        const rankings = data.rankings || data;
        if (rankings.length === 0) return;

        const topPerformer = rankings[0];
        const mostImproved = this.findMostImproved(rankings);

        const html = `
            <div class="top-performers-section">
                <h3 class="top-performers-title">🏆 Top Performers</h3>
                <div class="top-performers-grid">
                    <div class="performer-card top-performer">
                        <div class="performer-badge-label">Top Performer</div>
                        ${topPerformer.avatar_url ? `<img src="${topPerformer.avatar_url}" class="performer-avatar" alt="">` : ''}
                        <div class="performer-name">${topPerformer.name}</div>
                        <div class="performer-stat">
                            <div class="performer-stat-value">${topPerformer.points}</div>
                            <div>Points Earned</div>
                        </div>
                    </div>
                    ${mostImproved ? `
                    <div class="performer-card most-improved">
                        <div class="performer-badge-label">Most Improved</div>
                        ${mostImproved.avatar_url ? `<img src="${mostImproved.avatar_url}" class="performer-avatar" alt="">` : ''}
                        <div class="performer-name">${mostImproved.name}</div>
                        <div class="performer-stat">
                            <div class="performer-stat-value">+${mostImproved.improvement}</div>
                            <div>Rank Improvement</div>
                        </div>
                    </div>
                    ` : ''}
                </div>
            </div>
        `;

        let performersContainer = document.getElementById('topPerformersContainer');
        if (!performersContainer) {
            performersContainer = document.createElement('div');
            performersContainer.id = 'topPerformersContainer';
            this.container.appendChild(performersContainer);
        }
        performersContainer.innerHTML = html;
    }

    findMostImproved(rankings) {
        // Simple heuristic: player with highest wins relative to position
        return rankings.reduce((max, current) => {
            const currentScore = (current.wins || 0) * 10 - (current.ranking || 0);
            const maxScore = (max.wins || 0) * 10 - (max.ranking || 0);
            
            if (currentScore > maxScore) {
                return { ...current, improvement: currentScore };
            }
            return max;
        });
    }

    toggleComparisonMode() {
        this.comparisonMode = !this.comparisonMode;
        // TODO: Implement month-to-month comparison UI
        console.log('Comparison mode:', this.comparisonMode);
    }

    exportLeaderboard() {
        const table = document.querySelector('.snapshot-leaderboard-table');
        if (!table) return;

        let csv = 'Rank,Player,Points,Wins,Avg Placement\n';
        
        table.querySelectorAll('tbody tr').forEach(row => {
            const cells = row.querySelectorAll('td');
            csv += `${cells[0].textContent},${cells[1].textContent},${cells[2].textContent},${cells[3].textContent},${cells[4].textContent}\n`;
        });

        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `leaderboard-${this.formatMonth(this.currentMonth)}.csv`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    }

    showError(message) {
        console.error(message);
        // Could show error in UI here
    }
}

// Auto-initialize
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('leaderboardMonthlyContainer')) {
        window.monthlyLeaderboard = new MonthlyLeaderboard();
    }
});
