import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'status', 'summary', 'results', 'topScore', 'fairness', 'count', 'progress', 'progressBar', 'progressLabel'];
    static values = {
        endpoint: String,
    };

    connect() {
        this.loaded = false;
        this.expanded = false;
        this.progressTimer = null;
        this.currentProgress = 0;
        this.updateButtonLabel();
    }

    disconnect() {
        this.stopProgressSimulation();
    }

    async toggle(event) {
        if (event) {
            event.preventDefault();
        }

        if (!this.loaded) {
            await this.load();
            return;
        }

        this.expanded = !this.expanded;
        this.summaryTarget.classList.toggle('hidden', !this.expanded);
        this.resultsTarget.classList.toggle('hidden', !this.expanded);
        this.statusTarget.classList.toggle('hidden', !this.expanded);
        if (this.hasProgressTarget) {
            this.progressTarget.classList.toggle('hidden', !this.expanded);
        }
        this.updateButtonLabel();
    }

    async load() {
        this.setLoading(true);
        this.showProgress();
        this.setProgress(8, 'Préparation du matching...');
        this.startProgressSimulation();
        this.statusTarget.classList.remove('hidden');
        this.statusTarget.textContent = 'Calcul du matching en cours pour cette offre...';
        this.statusTarget.className = 'mt-4 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-300';

        try {
            const response = await fetch(this.endpointValue, {
                headers: {
                    Accept: 'application/json',
                },
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || `HTTP ${response.status}`);
            }

            this.setProgress(92, 'Finalisation de l’analyse...');
            this.renderSummary(payload);
            this.renderResults(payload);
            this.loaded = true;
            this.expanded = true;
            this.summaryTarget.classList.remove('hidden');
            this.resultsTarget.classList.remove('hidden');
            this.stopProgressSimulation();
            this.setProgress(100, 'Matching terminé.');
            this.setStatus('Matching calculé pour cette offre.');
            this.updateButtonLabel();
        } catch (error) {
            this.stopProgressSimulation();
            this.setProgress(100, 'Le calcul a échoué.');
            this.setStatus(`Impossible de calculer le matching pour cette offre : ${String(error)}`, true);
        } finally {
            this.setLoading(false);
        }
    }

    renderSummary(payload) {
        this.topScoreTarget.textContent = this.formatPercentage(payload.topMatchPercentage);
        this.fairnessTarget.textContent = this.formatNumber(payload?.enriched?.fairness?.disparate_impact_ratio);
        this.countTarget.textContent = `${payload.displayedMatches ?? 0} / ${payload.matchesCount ?? 0}`;
    }

    renderResults(payload) {
        const matches = Array.isArray(payload.matches) ? payload.matches : [];
        this.resultsTarget.innerHTML = '';

        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'rounded-2xl border border-dashed border-white/10 bg-slate-950/30 px-4 py-5 text-sm text-slate-500';
            empty.textContent = 'Aucun profil correspondant n’a été trouvé pour cette offre.';
            this.resultsTarget.appendChild(empty);
            return;
        }

        matches.forEach((match, index) => {
            const article = document.createElement('article');
            article.className = 'rounded-[1.5rem] border border-white/10 bg-slate-950/40 p-5';

            const hardSkills = Array.isArray(match.matchedHardSkills) ? match.matchedHardSkills : [];
            const softSkills = Array.isArray(match.matchedSoftSkills) ? match.matchedSoftSkills : [];
            const inferredTechnical = Array.isArray(match.inferredTechnicalSkills) ? match.inferredTechnicalSkills : [];
            const inferredSoft = Array.isArray(match.inferredSoftSkills) ? match.inferredSoftSkills : [];
            const inferredTransferable = Array.isArray(match.inferredTransferableSkills) ? match.inferredTransferableSkills : [];

            article.innerHTML = `
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-300">Top ${index + 1}</p>
                        <h3 class="mt-2 text-lg font-semibold text-white"></h3>
                        <p class="mt-1 text-sm text-slate-300">${this.escapeHtml(match.headline || 'Headline non renseignée')}</p>
                        <p class="mt-2 text-xs text-slate-400">${this.escapeHtml(String(match.yearsExperience ?? 0))} an(s) d'expérience</p>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-right">
                            <p class="text-xs uppercase tracking-wider text-emerald-200">Baseline</p>
                            <p class="mt-1 text-sm font-semibold text-white">${this.formatPercentage(match.percentage)}</p>
                        </div>
                        <div class="rounded-2xl border border-violet-500/20 bg-violet-500/10 px-4 py-3 text-right">
                            <p class="text-xs uppercase tracking-wider text-violet-200">CamemBERT</p>
                            <p class="mt-1 text-sm font-semibold text-white">${this.formatPercentage(match.semanticPercentage)}</p>
                        </div>
                        <div class="rounded-2xl border border-fuchsia-500/20 bg-fuchsia-500/10 px-4 py-3 text-right">
                            <p class="text-xs uppercase tracking-wider text-fuchsia-200">Enrichi</p>
                            <p class="mt-1 text-sm font-semibold text-white">${this.formatPercentage(match.semanticEnrichedPercentage)}</p>
                        </div>
                    </div>
                </div>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Compétences correspondantes</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            ${this.renderTags(hardSkills, 'border-emerald-500/20 bg-emerald-500/10 text-emerald-200')}
                            ${this.renderTags(softSkills, 'u-border-pale-pink u-bg-pale-pink u-text-pale-pink')}
                            ${hardSkills.length === 0 && softSkills.length === 0 ? '<span class="text-sm text-slate-500">Aucune correspondance directe détectée.</span>' : ''}
                        </div>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Compétences inférées</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            ${this.renderTags(inferredSoft, 'u-border-pale-pink u-bg-pale-pink u-text-pale-pink')}
                            ${this.renderTags(inferredTransferable, 'border-amber-500/20 bg-amber-500/10 text-amber-200')}
                            ${this.renderTechnicalTags(inferredTechnical)}
                            ${inferredSoft.length === 0 && inferredTransferable.length === 0 && inferredTechnical.length === 0 ? '<span class="text-sm text-slate-500">Aucune compétence implicite détectée.</span>' : ''}
                        </div>
                    </div>
                </div>
            `;

            const title = article.querySelector('h3');
            if (title) {
                if (match.profileUrl) {
                    title.innerHTML = `<a href="${this.escapeHtml(match.profileUrl)}" class="hover:text-emerald-300">${this.escapeHtml(match.fullName || 'Profil')}</a>`;
                } else {
                    title.textContent = match.fullName || 'Profil';
                }
            }

            this.resultsTarget.appendChild(article);
        });
    }

    renderTags(values, className) {
        return values.map((value) => `<span class="rounded-full border px-3 py-1 text-xs font-semibold ${className}">${this.escapeHtml(value)}</span>`).join('');
    }

    renderTechnicalTags(values) {
        return values.map((item) => `<span class="rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-200">${this.escapeHtml(item.skill || '')} · ${this.escapeHtml(item.level || '')}</span>`).join('');
    }

    updateButtonLabel() {
        if (!this.hasButtonTarget) {
            return;
        }

        if (!this.loaded) {
            this.buttonTarget.textContent = 'Calculer le matching';
            return;
        }

        this.buttonTarget.textContent = this.expanded ? 'Masquer le matching' : 'Afficher le matching';
    }

    setLoading(isLoading) {
        if (!this.hasButtonTarget) {
            return;
        }

        this.buttonTarget.disabled = isLoading;
        this.buttonTarget.classList.toggle('opacity-60', isLoading);
        this.buttonTarget.classList.toggle('cursor-not-allowed', isLoading);
    }

    showProgress() {
        if (!this.hasProgressTarget) {
            return;
        }

        this.progressTarget.classList.remove('hidden');
    }

    startProgressSimulation() {
        this.stopProgressSimulation();
        this.progressTimer = window.setInterval(() => {
            const nextValue = Math.min(this.currentProgress + (this.currentProgress < 60 ? 9 : 4), 88);
            if (nextValue !== this.currentProgress) {
                this.setProgress(nextValue, nextValue < 45 ? 'Analyse des profils publics...' : 'Calcul des scores sémantiques...');
            }
        }, 350);
    }

    stopProgressSimulation() {
        if (this.progressTimer !== null) {
            window.clearInterval(this.progressTimer);
            this.progressTimer = null;
        }
    }

    setProgress(value, label) {
        this.currentProgress = value;

        if (this.hasProgressBarTarget) {
            this.progressBarTarget.style.width = `${value}%`;
            this.progressBarTarget.setAttribute('aria-valuenow', String(value));
        }

        if (this.hasProgressLabelTarget) {
            this.progressLabelTarget.textContent = `${label} ${value}%`;
        }
    }

    setStatus(message, isError = false) {
        this.statusTarget.textContent = message;
        this.statusTarget.className = isError
            ? 'mt-4 rounded-2xl border border-rose-500/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-100'
            : 'mt-4 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-300';
    }

    formatNumber(value) {
        return typeof value === 'number' ? value.toFixed(2) : '-';
    }

    formatPercentage(value) {
        return typeof value === 'number' ? `${value.toFixed(1)}%` : '-';
    }

    escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
}
