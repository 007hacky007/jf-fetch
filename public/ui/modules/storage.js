import { state, els, API } from './context.js';
import { fetchJson, toggleElement, messageFromError, formatRelativeSize, escapeHtml } from './utils.js';

export function wireStorage() {
	els.storageRefreshBtn?.addEventListener('click', () => loadStorage(true));
}

export function startStorageAutoRefresh() {
	if (state.storageIntervalId !== null) {
		return;
	}

	state.storageIntervalId = window.setInterval(() => {
		loadStorage();
	}, 60000);
}

export function stopStorageAutoRefresh() {
	if (state.storageIntervalId === null) {
		return;
	}

	clearInterval(state.storageIntervalId);
	state.storageIntervalId = null;
}

export async function loadStorage(force = false) {
	if (!state.user) return;
	const now = Date.now();
	if (!force && state.storageUpdatedAt && now - state.storageUpdatedAt < 60000) {
		return;
	}

	toggleElement(els.storageLoading, true);
	toggleElement(els.storageError, false);

	try {
		const response = await fetchJson(API.systemStorage);
		state.storage = Array.isArray(response?.mounts) ? response.mounts : [];
		state.storageUpdatedAt = now;
		renderStorage();
	} catch (error) {
		state.storage = [];
		toggleElement(els.storageError, true);
		if (els.storageError) {
			els.storageError.textContent = messageFromError(error);
		}
	} finally {
		toggleElement(els.storageLoading, false);
	}
}

export function renderStorage() {
	if (!els.storageMounts) return;
	if (state.storage.length === 0) {
		els.storageMounts.innerHTML = '<p class="text-sm text-slate-400">No storage data yet.</p>';
		return;
	}

	els.storageMounts.innerHTML = state.storage
		.map((mount) => {
			const status = String(mount.status ?? 'ok');
			const isOk = status === 'ok';
			const totalBytesRaw = Number(mount.total_bytes ?? mount.total ?? 0);
			const freeBytesRaw = Number(mount.free_bytes ?? mount.free ?? 0);
			const totalBytes = Number.isFinite(totalBytesRaw) && totalBytesRaw > 0 ? totalBytesRaw : 0;
			const freeBytes = Number.isFinite(freeBytesRaw) && freeBytesRaw >= 0 ? freeBytesRaw : 0;
			const usedInput = mount.used_bytes ?? (totalBytes > 0 ? totalBytes - freeBytes : 0);
			const usedBytesRaw = Number(usedInput);
			const usedBytes = Number.isFinite(usedBytesRaw) && usedBytesRaw >= 0 ? usedBytesRaw : 0;
			const totalSafe = totalBytes > 0 ? totalBytes : usedBytes + freeBytes;
			const percent = totalSafe > 0 ? Math.min(100, Math.max(0, Math.round((usedBytes / totalSafe) * 100))) : 0;
			const resolvedPath = mount.resolved_path && mount.resolved_path !== mount.path ? `<div class="text-xs text-slate-500">Resolved: ${escapeHtml(String(mount.resolved_path))}</div>` : '';
			const statusBadge = isOk
				? ''
				: `<div class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-2 text-xs text-amber-200">${escapeHtml(mount.error ?? 'Storage information unavailable.')}</div>`;
			const usageDisplay = isOk
				? `${formatRelativeSize(usedBytes)} / ${formatRelativeSize(totalSafe)} (${percent}%)`
				: 'Unavailable';
			return `
				<div class="space-y-2">
					<div class="flex items-center justify-between text-sm text-slate-300">
						<div class="space-y-1">
							<span class="font-medium text-slate-200">${escapeHtml(mount.path ?? '')}</span>
							${resolvedPath}
						</div>
						<span>${usageDisplay}</span>
					</div>
					<div class="relative h-2 overflow-hidden rounded-full bg-slate-800/60">
						<div class="absolute inset-y-0 left-0 rounded-r-full ${isOk ? 'bg-emerald-500' : 'bg-amber-500/80'}" style="width: ${percent}%;"></div>
					</div>
					${statusBadge}
				</div>
			`;
		})
		.join('');
}
