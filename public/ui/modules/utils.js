import { els } from './context.js';

export function parseIsoDate(timestamp) {
	if (!timestamp) return null;
	const direct = new Date(timestamp);
	if (!Number.isNaN(direct.getTime())) {
		return direct;
	}
	if (typeof timestamp === 'string') {
		const trimmed = timestamp.replace(/(\.\d{3})\d+/, '$1');
		const fallback = new Date(trimmed);
		if (!Number.isNaN(fallback.getTime())) {
			return fallback;
		}
	}
	return null;
}

export function toggleElement(element, show, displayClass) {
	if (!element) return;
	if (show) {
		element.classList.remove('hidden');
		if (displayClass) element.classList.add(displayClass);
	} else {
		element.classList.add('hidden');
		if (displayClass) element.classList.remove(displayClass);
	}
}

export async function fetchJson(url, options = {}) {
	const response = await fetch(url, {
		credentials: 'include',
		headers: {
			'Content-Type': 'application/json',
			...(options.headers ?? {}),
		},
		...options,
	});

	const contentType = response.headers.get('Content-Type') ?? '';
	const isJson = contentType.includes('application/json');
	let payload = null;

	if (isJson) {
		try {
			payload = await response.json();
		} catch (error) {
			throw new Error('Failed to parse server response.');
		}
	} else {
		payload = await response.text();
	}

	if (!response.ok) {
		const message = typeof payload === 'string' ? payload : payload?.error ?? 'Request failed';
		const error = new Error(message);
		error.status = response.status;
		error.details = typeof payload === 'object' && payload !== null ? payload.errors ?? null : null;
		throw error;
	}

	return payload;
}

export function formatBytes(bytes) {
	const value = Number(bytes ?? 0);
	if (!Number.isFinite(value) || value < 0) return '0 B';
	const units = ['B', 'KB', 'MB', 'GB', 'TB'];
	const power = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
	const result = value / 1024 ** power;
	return `${result.toFixed(power === 0 ? 0 : 2)} ${units[power]}`;
}

export function showToast(message, variant = 'info', timeout = 3500) {
	if (!els.toastContainer) return;
	const toast = document.createElement('div');
	toast.className = 'toast';
	toast.dataset.variant = variant;
	toast.innerHTML = `
		<span>${escapeHtml(message)}</span>
		<button type="button" aria-label="Close">×</button>
	`;
	const closeBtn = toast.querySelector('button');
	closeBtn?.addEventListener('click', () => dismissToast(toast));
	els.toastContainer.appendChild(toast);
	setTimeout(() => dismissToast(toast), timeout);
}

export function dismissToast(toast) {
	if (!toast) return;
	toast.classList.add('fade-out');
	setTimeout(() => toast.remove(), 250);
}

export function escapeHtml(value) {
	return String(value ?? '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
}

export function messageFromError(error) {
	if (!error) return 'Unknown error';
	if (typeof error === 'string') return error;
	if (error.message) return error.message;
	return 'Request failed';
}

export function formatRelativeSize(bytes) {
	const value = Number(bytes ?? 0);
	if (!Number.isFinite(value) || value <= 0) return '0 B';
	const units = ['B', 'KB', 'MB', 'GB', 'TB'];
	const power = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
	const result = value / 1024 ** power;
	return `${result.toFixed(power === 0 ? 0 : 2)} ${units[power]}`;
}

export function formatDuration(seconds) {
	const value = Number(seconds ?? 0);
	if (!Number.isFinite(value) || value <= 0) return 'Unknown duration';
	const mins = Math.floor(value / 60);
	const secs = Math.floor(value % 60);
	if (mins >= 60) {
		const hours = Math.floor(mins / 60);
		const remMins = mins % 60;
		return `${hours}h ${remMins}m`;
	}
	return `${mins}m ${secs}s`;
}

export function formatBitrate(kbps) {
	const value = Number(kbps ?? 0);
	if (!Number.isFinite(value) || value <= 0) return null;
	if (value >= 1000) {
		const mbps = value / 1000;
		const formatted = mbps >= 10 ? mbps.toFixed(0) : mbps.toFixed(1);
		return `${Number(formatted)} Mbps`;
	}
	return `${Math.round(value)} kbps`;
}

export function formatFps(fps) {
	const value = Number(fps ?? 0);
	if (!Number.isFinite(value) || value <= 0) return null;
	const rounded = Math.round(value * 100) / 100;
	const isWhole = Math.abs(rounded - Math.round(rounded)) < 0.01;
	const display = isWhole
		? String(Math.round(rounded))
		: rounded.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
	return `${display} fps`;
}

export function formatAudioChannels(channels) {
	const value = Number(channels ?? 0);
	if (!Number.isFinite(value) || value <= 0) return null;
	const mapping = {
		1: 'Mono',
		2: 'Stereo',
		6: '5.1',
		8: '7.1',
	};
	if (mapping[value]) {
		return mapping[value];
	}
	return `${value}-ch`;
}

export function formatSpeed(bps) {
	const value = Number(bps ?? 0);
	if (!Number.isFinite(value) || value <= 0) return 'Idle';
	return `${formatRelativeSize(value)}/s`;
}

export function formatRelativeTime(timestamp) {
	if (!timestamp) return 'Unknown';
	const date = parseIsoDate(timestamp);
	if (!date) return 'Unknown';
	const now = Date.now();
	const diff = now - date.getTime();
	const minute = 60 * 1000;
	const hour = minute * 60;
	const day = hour * 24;
	if (diff < minute) return 'Just now';
	if (diff < hour) return `${Math.round(diff / minute)} min ago`;
	if (diff < day) return `${Math.round(diff / hour)} h ago`;
	return date.toLocaleString();
}

export function statusBadgeColor(status) {
	const base = 'rounded-full border px-2 py-0.5 text-xs uppercase tracking-wide';
	switch (status) {
		case 'downloading':
			return `${base} border-emerald-500/40 bg-emerald-500/20 text-emerald-100`;
		case 'starting':
			return `${base} border-brand-500/40 bg-brand-500/20 text-brand-100`;
		case 'paused':
			return `${base} border-amber-500/40 bg-amber-500/20 text-amber-100`;
		case 'failed':
			return `${base} border-rose-500/40 bg-rose-500/20 text-rose-100`;
		case 'completed':
			return `${base} border-emerald-500/50 bg-emerald-500/30 text-emerald-100`;
		case 'deleted':
			return `${base} border-amber-400/40 bg-amber-500/10 text-amber-100`;
		default:
			return `${base} border-slate-700/70 bg-slate-900/70 text-slate-300`;
	}
}

export function progressBarColor(status) {
	switch (status) {
		case 'completed':
			return 'bg-emerald-500';
		case 'failed':
			return 'bg-rose-500';
		case 'paused':
		case 'deleted':
			return 'bg-amber-500';
		default:
			return 'bg-brand-500';
	}
}
