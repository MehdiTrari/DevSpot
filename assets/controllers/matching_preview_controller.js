import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['payload', 'status', 'summary', 'results', 'rawResponse', 'topCandidate', 'topScore', 'fairness'];
    static values = {
        endpoint: String,
    };

    async run(event) {
        const trigger = event && event.currentTarget ? event.currentTarget : null;
        let parsedPayload;

        try {
            parsedPayload = JSON.parse(this.payloadTarget.value);
        } catch (error) {
            this.setStatus('Le JSON est invalide. Corrige le payload avant de relancer.', true);
            this.rawResponseTarget.textContent = String(error);
            this.summaryTarget.classList.add('hidden');
            this.resultsTarget.innerHTML = '';
            return;
        }

        if (trigger instanceof HTMLButtonElement) {
            trigger.disabled = true;
            trigger.classList.add('opacity-60', 'cursor-not-allowed');
        }

        this.setStatus('Requête en cours vers l’API de matching...');

        try {
            const response = await fetch(this.endpointValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(parsedPayload),
            });

            const rawText = await response.text();
            let data;
            try {
                data = JSON.parse(rawText);
                this.rawResponseTarget.textContent = JSON.stringify(data, null, 2);
            } catch (parseError) {
                data = null;
                this.rawResponseTarget.textContent = rawText;
            }

            if (!response.ok || !data) {
                this.setStatus(`L'API a répondu avec une erreur HTTP ${response.status}.`, true);
                this.summaryTarget.classList.add('hidden');
                this.resultsTarget.innerHTML = '';
                return;
            }

            this.renderSummary(data);
            this.renderResults(data);
            this.setStatus('Matching exécuté. Le classement et les indicateurs sont affichés ci-dessous.');
        } catch (error) {
            this.setStatus('Impossible d’appeler l’API de matching depuis la page.', true);
            this.rawResponseTarget.textContent = String(error);
            this.summaryTarget.classList.add('hidden');
            this.resultsTarget.innerHTML = '';
        } finally {
            if (trigger instanceof HTMLButtonElement) {
                trigger.disabled = false;
                trigger.classList.remove('opacity-60', 'cursor-not-allowed');
            }
        }
    }

    setStatus(message, isError = false) {
        this.statusTarget.textContent = message;
        this.statusTarget.className = isError
            ? 'rounded-[1.5rem] border border-rose-500/20 bg-rose-500/10 px-4 py-4 text-sm text-rose-100'
            : 'rounded-[1.5rem] border border-white/10 bg-white/5 px-4 py-4 text-sm text-slate-300';
    }

    renderSummary(data) {
        const matches = Array.isArray(data.matches) ? data.matches : [];
        const topMatch = matches[0] || {};
        const fairness = data.enriched && data.enriched.fairness ? data.enriched.fairness : (data.fairness || {});

        this.topCandidateTarget.textContent = topMatch.candidateId || '-';
        this.topScoreTarget.textContent = this.formatPercentage(topMatch.semanticEnrichedPercentage);
        this.fairnessTarget.textContent = this.formatNumber(fairness.disparate_impact_ratio);
        this.summaryTarget.classList.remove('hidden');
    }

    renderResults(data) {
        const matches = Array.isArray(data.matches) ? data.matches : [];
        this.resultsTarget.innerHTML = '';

        if (matches.length === 0) {
            const emptyCard = document.createElement('div');
            emptyCard.className = 'rounded-[1.5rem] border border-dashed border-white/10 bg-slate-950/30 px-4 py-6 text-sm text-slate-500';
            emptyCard.textContent = 'Le matching n\'a retourné aucun candidat.';
            this.resultsTarget.appendChild(emptyCard);
            return;
        }

        matches.forEach((match, index) => {
            const card = document.createElement('article');
            card.className = 'rounded-[1.5rem] border border-white/10 bg-slate-950/40 p-5';
            const breakdown = match.scoreBreakdown || {};
            const anonymizedCv = typeof match.anonymizedCv === 'string' ? match.anonymizedCv : '';

            card.innerHTML = `
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-300">Classement #${index + 1}</p>
                        <h4 class="mt-2 text-lg font-semibold text-white">${match.candidateId ?? '-'}</h4>
                        <p class="mt-1 text-sm text-slate-400">${match.yearsOfExperience ?? 0} an(s) d'expérience</p>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-right">
                            <p class="text-xs uppercase tracking-wider text-emerald-200">Baseline</p>
                            <p class="mt-1 text-sm font-semibold text-white">${this.formatNumber(match.score)}</p>
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
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                        <p class="text-xs uppercase tracking-wider text-slate-400">Hard skills</p>
                        <p class="mt-2 text-sm font-semibold text-white">${this.formatNumber(breakdown.hard_skills)}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                        <p class="text-xs uppercase tracking-wider text-slate-400">Soft skills</p>
                        <p class="mt-2 text-sm font-semibold text-white">${this.formatNumber(breakdown.soft_skills)}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                        <p class="text-xs uppercase tracking-wider text-slate-400">Junior boost</p>
                        <p class="mt-2 text-sm font-semibold text-white">${this.formatNumber(breakdown.junior_boost)}</p>
                    </div>
                </div>
                <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                    <p class="text-xs uppercase tracking-wider text-slate-400">CV anonymisé</p>
                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-200"></p>
                </div>
            `;
            card.querySelector('.whitespace-pre-wrap').textContent = anonymizedCv;
            this.resultsTarget.appendChild(card);
        });
    }

    formatNumber(value) {
        return typeof value === 'number' ? value.toFixed(4) : '-';
    }

    formatPercentage(value) {
        return typeof value === 'number' ? `${value.toFixed(1)}%` : '-';
    }
}
