import { state, els } from './context.js';
import { toggleElement, escapeHtml, formatRelativeTime } from './utils.js';

export function wireActivity() {
	els.activityClearBtn?.addEventListener('click', () => {
		state.activity = [];
		renderActivity();
	});
}

export function renderActivity() {
	if (!els.activityFeed || !els.activityEmpty) return;
	if (state.activity.length === 0) {
		toggleElement(els.activityEmpty, true);
		els.activityFeed.innerHTML = '';
		return;
	}

	toggleElement(els.activityEmpty, false);
	els.activityFeed.innerHTML = state.activity
		.map((item) => `
			<li class="rounded-xl border border-slate-800/70 bg-slate-900/60 p-3 text-sm text-slate-200">
				<div class="flex items-center justify-between text-xs text-slate-400">
					<span>${escapeHtml(item.type)}</span>
					<span>${formatRelativeTime(item.at)}</span>
				</div>
				<div class="mt-1 font-semibold text-slate-100">${escapeHtml(item.title)}</div>
				${item.status ? `<div class="text-xs text-slate-400">Status: ${escapeHtml(item.status)}</div>` : ''}
			</li>
		`)
		.join('');
}
