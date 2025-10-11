import { state, els, API, JOB_STREAM_EVENTS } from './context.js';
import {
	fetchJson,
	toggleElement,
	showToast,
	messageFromError,
	escapeHtml,
	formatSpeed,
	formatDuration,
	statusBadgeColor,
	formatRelativeTime,
	progressBarColor,
	parseIsoDate,
} from './utils.js';
import { renderActivity } from './activity.js';

let dragSourceId = null;

export function wireJobs() {
	els.jobsMineToggle?.addEventListener('change', () => {
		state.showMyJobs = Boolean(els.jobsMineToggle?.checked);
		loadJobs();
	});

	els.jobsList?.addEventListener('click', (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;

		const button = target.closest('[data-job-action]');
		if (!button || !(button instanceof HTMLElement)) return;

		const action = button.dataset.jobAction;
		const jobId = Number.parseInt(button.dataset.jobId ?? '0', 10);
		if (!action || !jobId) return;

		handleJobAction(jobId, action);
	});

	els.jobsList?.addEventListener('change', (event) => {
		const target = event.target;
		if (!(target instanceof HTMLInputElement)) return;
		if (target.name !== 'job-priority') return;

		const jobId = Number.parseInt(target.dataset.jobId ?? '0', 10);
		if (!jobId) return;

		updateJobPriority(jobId, Number.parseInt(target.value, 10) || 0);
	});

	setupDragAndDrop();
}

export async function loadJobs() {
	if (!state.user) return;
	toggleElement(els.jobsLoading, true);

	const params = new URLSearchParams();
	if (state.showMyJobs) params.set('mine', '1');

	try {
		const response = await fetchJson(`${API.jobsList}?${params.toString()}`);
		const jobs = Array.isArray(response?.data) ? response.data : [];
		state.jobs = jobs.map((job) => normalizeJob(job)).filter(Boolean);
		sortJobsInPlace(state.jobs);
		renderJobs();
	} catch (error) {
		showToast(`Failed to load jobs: ${messageFromError(error)}`, 'error');
		state.jobs = [];
		renderJobs();
	} finally {
		toggleElement(els.jobsLoading, false);
	}
}

export function renderJobs() {
	if (!els.jobsList) return;

	if (state.jobs.length === 0) {
		toggleElement(els.jobsEmpty, true);
		els.jobsList.innerHTML = '';
		els.jobsSummary.textContent = 'No jobs yet.';
		return;
	}

	toggleElement(els.jobsEmpty, false);
	els.jobsSummary.textContent = `${state.jobs.length} job${state.jobs.length === 1 ? '' : 's'} in queue.`;

	els.jobsList.innerHTML = state.jobs.map((job) => renderJobItem(job)).join('');

	els.jobsList.querySelectorAll('[draggable="true"]').forEach((element) => {
		element.addEventListener('dragstart', onDragStart);
		element.addEventListener('dragover', onDragOver);
		element.addEventListener('drop', onDrop);
	});
}

function getJobDisplayState(job) {
	if (!job || typeof job !== 'object') return null;

	const status = job.status ?? 'queued';
	const statusLabel = job.status_label ?? (typeof status === 'string' ? status.replace(/_/g, ' ') : 'queued');
	const isOwner = state.user && Number(job.user_id ?? job.user?.id ?? 0) === Number(state.user.id);
	const canControl = state.isAdmin || Boolean(isOwner);
	const rawProgress = Number(job.progress ?? 0);
	const progress = Number.isFinite(rawProgress) ? Math.max(0, Math.min(100, rawProgress)) : 0;
	const statusBadgeClass = statusBadgeColor(status);
	const speed = job.speed_bps ? `${formatSpeed(job.speed_bps)} • ` : '';
	const eta = job.eta_seconds ? `ETA ${formatDuration(job.eta_seconds)}` : 'Awaiting updates';
	const ownerName = (job.user_name ?? '').trim();
	const ownerEmail = (job.user_email ?? '').trim();
	const ownerLabel = ownerName && ownerEmail ? `${ownerName} (${ownerEmail})` : ownerName || ownerEmail || 'Unknown user';
	const deletedAt = job.deleted_at ? formatRelativeTime(job.deleted_at) : null;
	const isDeleted = status === 'deleted';
	const isCompleted = status === 'completed';
	const completedFile = typeof job.final_path === 'string' ? job.final_path.split(/[/\\]/).filter(Boolean).pop() : null;
	const progressLabel = isDeleted ? 'File deleted' : `Progress ${Math.round(progress)}%`;
	const statusMeta = isDeleted
		? (deletedAt ? `Deleted ${deletedAt}` : 'Deleted by request')
		: isCompleted
		? (completedFile ? `Saved as ${completedFile}` : 'Completed')
		: `${speed}${eta}`;
	const progressValue = isDeleted ? 0 : progress;
	const progressPercent = Number.isFinite(progressValue) ? Math.max(0, Math.min(100, progressValue)) : 0;
	const progressWidth = `${progressPercent.toFixed(2)}%`;
	const progressColorClass = progressBarColor(status);
	// Create a textual (wget-like) progress bar representation
	const progressText = generateTextProgressBar(progressPercent, 30);
	const errorNote = !isDeleted && job.error_text ? String(job.error_text) : '';
	const deletionNote = isDeleted ? String(job.error_text ?? 'Downloaded file removed.') : '';
	const priorityDisabled = ['completed', 'failed', 'canceled', 'deleted'].includes(status);
	const draggable = status === 'queued';

	return {
		status,
		statusLabel,
		statusBadgeClass,
		isOwner,
		canControl,
		progress,
		progressLabel,
		statusMeta,
		progressWidth,
		progressColorClass,
		progressText,
		errorNote,
		deletionNote,
		ownerLabel,
		priorityDisabled,
		draggable,
	};
}

// Generate a wget-like textual progress bar. Example: 25% [======>                              ]
// width specifies the number of character cells inside the brackets.
function generateTextProgressBar(percent, width = 30) {
	const p = Math.max(0, Math.min(100, Number(percent) || 0));
	const cells = Math.max(10, Math.min(120, Math.floor(width)));
	const filled = Math.floor((p / 100) * cells);
	const showArrow = p < 100 && filled < cells; // Show an arrow for in-progress state
	const barFilled = '='.repeat(Math.max(0, filled - (showArrow ? 1 : 0)));
	const barArrow = showArrow ? '>' : (p >= 100 ? '=' : '');
	const barEmpty = ' '.repeat(Math.max(0, cells - filled));
	const bar = `${barFilled}${barArrow}${barEmpty}`;
	const pct = `${Math.round(p)}%`.padStart(4, ' ');
	return `${pct} [${bar}]`;
}

function renderJobItem(job) {
 	const display = getJobDisplayState(job);
 	if (!display) return '';

	const actions = renderJobActions(job, display.canControl);
	const deletionNote = display.deletionNote
		? `<div class="rounded-lg border border-amber-500/40 bg-amber-500/15 p-2 text-xs text-amber-100">${escapeHtml(display.deletionNote)}</div>`
		: '';
	const errorNote = !display.deletionNote && display.errorNote
		? `<div class="text-xs text-rose-300">${escapeHtml(display.errorNote)}</div>`
		: '';
	const priorityValue = escapeHtml(String(job.priority ?? 100));
	const title = escapeHtml(job.title ?? job.external_id ?? 'Unknown title');
	const providerLabel = escapeHtml(job.provider_key ?? '');

	return `
		<li data-job-id="${job.id}" data-job-status="${escapeHtml(display.status)}" draggable="${display.draggable ? 'true' : 'false'}" class="group rounded-2xl border border-slate-800/70 bg-slate-900/60 p-4">
			<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
				<div class="space-y-2">
					<div class="flex flex-wrap items-center gap-2">
						<h4 class="text-lg font-semibold text-slate-100" data-job-title>${title}</h4>
						<span class="rounded-full border border-slate-800/60 bg-slate-950/60 px-2 py-0.5 text-xs uppercase tracking-wide text-slate-300" data-job-provider>${providerLabel}</span>
						<span class="${display.statusBadgeClass}" data-job-status-badge>${escapeHtml(display.statusLabel)}</span>
					</div>
					<div class="flex flex-wrap gap-2 text-xs text-slate-400">
						<span data-job-owner>Owner ${escapeHtml(display.ownerLabel)}</span>
						<span>Priority ${escapeHtml(String(job.priority ?? 100))}</span>
					</div>
					<div class="flex items-center justify-between text-xs text-slate-300">
						<span data-job-progress-label>${escapeHtml(display.progressLabel)}</span>
						<span data-job-status-meta>${escapeHtml(display.statusMeta)}</span>
					</div>
					<pre data-job-progress-text class="font-mono text-[11px] leading-tight text-slate-300 whitespace-pre overflow-hidden">${escapeHtml(display.progressText)}</pre>
					${deletionNote || errorNote}
				</div>
				<div class="flex flex-col items-end gap-3">
					${display.canControl ? actions : ''}
					<label class="flex items-center gap-2 text-xs text-slate-300">
						Priority
						<input type="number" name="job-priority" data-job-id="${job.id}" value="${priorityValue}" ${display.priorityDisabled ? 'disabled' : ''} class="w-20 rounded border border-slate-700 bg-slate-950/60 px-2 py-1 text-slate-100 focus:border-brand-400 focus:outline-none focus:ring focus:ring-brand-500/40 disabled:opacity-60" />
					</label>
				</div>
			</div>
		</li>
	`;
}

function updateJobDom(job) {
	if (!els.jobsList) return false;
	if (!job || job.id === undefined || job.id === null) return false;

	const display = getJobDisplayState(job);
	if (!display) return false;

	const item = els.jobsList.querySelector(`[data-job-id="${job.id}"]`);
	if (!(item instanceof HTMLElement)) return false;

	item.dataset.jobStatus = display.status;

	const statusBadge = item.querySelector('[data-job-status-badge]');
	if (statusBadge instanceof HTMLElement) {
		statusBadge.className = display.statusBadgeClass;
		statusBadge.textContent = display.statusLabel;
	}

	const owner = item.querySelector('[data-job-owner]');
	if (owner instanceof HTMLElement) {
		owner.textContent = `Owner ${display.ownerLabel}`;
	}

	const progressLabel = item.querySelector('[data-job-progress-label]');
	if (progressLabel instanceof HTMLElement) {
		progressLabel.textContent = display.progressLabel;
	}

	const statusMeta = item.querySelector('[data-job-status-meta]');
	if (statusMeta instanceof HTMLElement) {
		statusMeta.textContent = display.statusMeta;
	}

	// Legacy graphical bar removed; update textual bar instead
	const progressTextEl = item.querySelector('[data-job-progress-text]');
	if (progressTextEl instanceof HTMLElement) {
		progressTextEl.textContent = display.progressText;
	}

	return true;
}

function renderJobActions(job, canControl) {
	if (!canControl) return '';
	const buttons = [];
	const jobId = Number(job.id);
	const status = job.status ?? 'queued';

	if (status === 'queued' || status === 'downloading' || status === 'starting') {
		buttons.push(`<button type="button" data-job-action="cancel" data-job-id="${jobId}" class="job-btn job-btn-danger">Cancel</button>`);
	}

	if (status === 'downloading' || status === 'starting') {
		buttons.push(`<button type="button" data-job-action="pause" data-job-id="${jobId}" class="job-btn">Pause</button>`);
	}

	if (status === 'paused') {
		buttons.push(`<button type="button" data-job-action="resume" data-job-id="${jobId}" class="job-btn">Resume</button>`);
	}

	if (status === 'completed' && job.final_path) {
		buttons.push(`<button type="button" data-job-action="download" data-job-id="${jobId}" class="job-btn" title="Download file" aria-label="Download file">⬇️</button>`);
		buttons.push(`<button type="button" data-job-action="delete-file" data-job-id="${jobId}" class="job-btn job-btn-danger">Delete File</button>`);
	}

	return `<div class="flex flex-wrap justify-end gap-2">${buttons.join('')}</div>`;
}

async function handleJobAction(jobId, action) {
	let endpoint = '';
	let method = 'PATCH';
	let successMessage = '';

	switch (action) {
		case 'cancel':
			endpoint = API.jobCancel;
			successMessage = 'Job canceled.';
			break;
		case 'pause':
			endpoint = API.jobPause;
			successMessage = 'Job paused.';
			break;
		case 'resume':
			endpoint = API.jobResume;
			successMessage = 'Job resumed.';
			break;
		case 'download':
			// Directly trigger browser download; no fetch/JSON handling.
			window.open(`${API.jobDownload}?id=${jobId}`, '_blank');
			return; // No toast; server handles file response.
		case 'delete-file':
			endpoint = API.jobDelete;
			method = 'DELETE';
			successMessage = 'Downloaded file deleted.';
			if (!window.confirm('Delete the downloaded file? This cannot be undone.')) {
				return;
			}
			break;
		default:
			return;
	}

	try {
		await fetchJson(`${endpoint}?id=${jobId}`, { method });
		showToast(successMessage, 'success');
		await loadJobs();
	} catch (error) {
		const failurePrefix = action === 'delete-file' ? 'delete file' : `${action} job`;
		showToast(`Failed to ${failurePrefix}: ${messageFromError(error)}`, 'error');
	}
}

async function updateJobPriority(jobId, priority) {
	try {
		await fetchJson(`${API.jobPriority}?id=${jobId}`, {
			method: 'PATCH',
			body: JSON.stringify({ priority }),
		});
		showToast('Priority updated.', 'success');
		await loadJobs();
	} catch (error) {
		showToast(`Failed to update priority: ${messageFromError(error)}`, 'error');
	}
}

function setupDragAndDrop() {
	if (!els.jobsList) return;
	els.jobsList.addEventListener('dragenter', (event) => event.preventDefault());
}

function onDragStart(event) {
	const target = event.target;
	if (!(target instanceof HTMLElement)) return;
	dragSourceId = target.dataset.jobId ?? null;
	event.dataTransfer?.setData('text/plain', dragSourceId ?? '');
	event.dataTransfer?.setDragImage(target, 20, 20);
}

function onDragOver(event) {
	event.preventDefault();
	if (event.dataTransfer) {
		event.dataTransfer.dropEffect = 'move';
	}
}

async function onDrop(event) {
	event.preventDefault();
	const target = event.currentTarget;
	if (!(target instanceof HTMLElement)) return;
	const targetId = target.dataset.jobId ?? null;
	const sourceId = dragSourceId;
	dragSourceId = null;
	if (!sourceId || !targetId || sourceId === targetId) return;

	const order = reorderJobsLocal(Number(sourceId), Number(targetId));
	renderJobs();

	try {
		await fetchJson(API.jobReorder, {
			method: 'POST',
			body: JSON.stringify({ order }),
		});
		showToast('Queue reordered.', 'success');
	} catch (error) {
		showToast(`Failed to reorder: ${messageFromError(error)}`, 'error');
		await loadJobs();
	}
}

function reorderJobsLocal(sourceId, targetId) {
	const order = state.jobs.map((job) => Number(job.id));
	const sourceIndex = order.indexOf(sourceId);
	const targetIndex = order.indexOf(targetId);
	if (sourceIndex === -1 || targetIndex === -1) return order;
	order.splice(sourceIndex, 1);
	order.splice(targetIndex, 0, sourceId);
	state.jobs = order
		.map((id) => state.jobs.find((job) => Number(job.id) === id))
		.filter(Boolean);
	return order;
}

export function startJobStream() {
	if (state.jobStream || !state.user) return;
	try {
		const stream = new EventSource(API.jobStream);
		stream.addEventListener('open', () => {
			console.info('SSE connected');
			state.jobStreamConnectedAt = Date.now();
		});

		const attachHandler = (eventName) => {
			stream.addEventListener(eventName, (event) => {
				if (!event.data) return;
				try {
					const payload = JSON.parse(event.data);
					handleJobEvent({ type: event.type || eventName, job: payload });
				} catch (error) {
					console.warn('Failed to parse SSE payload', error);
				}
			});
		};

		JOB_STREAM_EVENTS.forEach(attachHandler);

		stream.addEventListener('message', (event) => {
			if (!event.data) return;
			try {
				const payload = JSON.parse(event.data);
				const type = event.type && event.type !== '' ? event.type : 'job.updated';
				handleJobEvent({ type, job: payload.job ?? payload });
			} catch (error) {
				console.warn('Failed to parse SSE payload', error);
			}
		});
		stream.addEventListener('error', () => {
			showToast('Lost connection to job stream. Reconnecting…', 'warning');
			stopJobStream();
			setTimeout(startJobStream, 3000);
		});
		state.jobStream = stream;
	} catch (error) {
		console.warn('Unable to start SSE stream', error);
	}
}

export function stopJobStream() {
	state.jobStream?.close();
	state.jobStream = null;
}

function handleJobEvent(event) {
	if (!event || !event.type) return;

	const job = event.job ?? {};
	// Capture previous job status (if any) before merge for change detection
	let previousStatus = null;
	if (job.id != null) {
		const existing = state.jobs.find((j) => Number(j.id) === Number(job.id));
		if (existing) previousStatus = existing.status;
	}
	const notificationKey = `${event.type}:${job.id ?? ''}`;
	if (['job.completed', 'job.failed', 'job.removed', 'job.deleted'].includes(event.type) && !state.jobNotifications.has(notificationKey)) {
		// Only show toast for recent events (avoid backlog spam on login)
		if (!isRecentJobEvent(event, job)) {
			// Skip toast; still record notification key to avoid future duplication
			state.jobNotifications.add(notificationKey);
		} else {
		state.jobNotifications.add(notificationKey);
		if (state.jobNotifications.size > 200) {
			const oldest = state.jobNotifications.values().next().value;
			if (oldest) {
				state.jobNotifications.delete(oldest);
			}
		}

		const title = job.title ?? (job.id ? `Job ${job.id}` : 'Job');
		switch (event.type) {
			case 'job.completed':
				showToast(`${title} completed successfully.`, 'success');
				break;
			case 'job.failed':
				showToast(`${title} failed.`, 'error');
				break;
			case 'job.removed':
				showToast(`${title} was removed.`, 'warning');
				break;
			case 'job.deleted':
				showToast(`${title} file deleted.`, 'info');
				break;
		}
		}
	}
	let requiresRefresh = false;

	if (event.type === 'job.removed') {
		const removed = removeJobFromState(job.id ?? job.job_id ?? null);
		if (!removed) {
			requiresRefresh = true;
		} else {
			renderJobs();
		}
	} else if (job && Object.keys(job).length > 0) {
		const merged = mergeJobIntoState(job);
		if (!merged || !merged.job) {
			requiresRefresh = true;
		} else if (merged.needsFullRender) {
			renderJobs();
		} else if (!updateJobDom(merged.job)) {
			renderJobs();
		}
	} else {
		requiresRefresh = true;
	}

	// After state updated (if merged), decide whether to log activity
	let currentStatus = job.status;
	if (!requiresRefresh && job.id != null) {
		const existingAfter = state.jobs.find((j) => Number(j.id) === Number(job.id));
		if (existingAfter) currentStatus = existingAfter.status;
	}

	const isStatusChange = previousStatus !== null && currentStatus !== previousStatus;
	const isNewJob = previousStatus === null && !!job.id;
	const isTerminalEvent = ['job.completed', 'job.failed', 'job.removed', 'job.deleted'].includes(event.type);
	const isNonUpdate = event.type !== 'job.updated';

	if (isNonUpdate || isStatusChange || isNewJob || isTerminalEvent) {
		state.activity.unshift({
			type: event.type,
			job_id: event.job?.id ?? null,
			title: event.job?.title ?? 'Unknown',
			status: currentStatus,
			at: new Date().toISOString(),
		});
		state.activity = state.activity.slice(0, 25);
		renderActivity();
	}

	if (requiresRefresh) {
		loadJobs();
	}
}

export function normalizeJob(job) {
	if (!job || typeof job !== 'object') return null;
	const provider = job.provider ?? {};
	const user = job.user ?? {};
	const deletedAt = job.deleted_at ?? job.deletedAt ?? null;
	const userName = job.user_name ?? user.name ?? job.userName ?? '';
	const userEmail = job.user_email ?? user.email ?? job.userEmail ?? '';
	const rawStatus = typeof job.status === 'string' ? job.status : '';
	const normalizedStatus = rawStatus.toLowerCase() || 'queued';
	let progressValue = Number(job.progress ?? job.progress_percent ?? job.percent ?? 0);
	if (!Number.isFinite(progressValue)) {
		progressValue = 0;
	} else if (progressValue > 0 && progressValue <= 1) {
		progressValue *= 100;
	}
	progressValue = Math.max(0, Math.min(100, progressValue));

	return {
		...job,
		status: normalizedStatus,
		status_label: rawStatus !== '' ? rawStatus : normalizedStatus,
		progress: progressValue,
		provider_key: job.provider_key ?? provider.key ?? job.providerKey ?? '',
		provider_name: job.provider_name ?? provider.name ?? job.providerName ?? '',
		user_name: typeof userName === 'string' ? userName : '',
		user_email: typeof userEmail === 'string' ? userEmail : '',
		deleted_at: deletedAt,
	};
}

function compareJobs(a, b) {
	const createdA = parseIsoDate(a?.created_at ?? 0)?.getTime();
	const createdB = parseIsoDate(b?.created_at ?? 0)?.getTime();
	if (Number.isFinite(createdA) && Number.isFinite(createdB) && createdA !== createdB) {
		return createdB - createdA;
	}

	const updatedA = parseIsoDate(a?.updated_at ?? 0)?.getTime();
	const updatedB = parseIsoDate(b?.updated_at ?? 0)?.getTime();
	if (Number.isFinite(updatedA) && Number.isFinite(updatedB) && updatedA !== updatedB) {
		return updatedB - updatedA;
	}

	const priorityDiff = Number(b?.priority ?? 0) - Number(a?.priority ?? 0);
	if (priorityDiff !== 0) {
		return priorityDiff;
	}

	return Number(b?.id ?? 0) - Number(a?.id ?? 0);
}

function sortJobsInPlace(jobs) {
	if (!Array.isArray(jobs)) return;
	jobs.sort(compareJobs);
}

function mergeJobIntoState(job) {
	const normalized = normalizeJob(job);
	if (!normalized || normalized.id === undefined || normalized.id === null) {
		return null;
	}

	const jobId = Number(normalized.id);
	if (!Number.isFinite(jobId)) {
		return null;
	}

	const previousIndex = state.jobs.findIndex((item) => Number(item.id) === jobId);
	const previousJob = previousIndex === -1 ? null : { ...state.jobs[previousIndex] };
	let added = false;

	if (previousIndex === -1) {
		state.jobs.push(normalized);
		added = true;
	} else {
		state.jobs[previousIndex] = {
			...state.jobs[previousIndex],
			...normalized,
		};
	}

	sortJobsInPlace(state.jobs);

	const currentIndex = state.jobs.findIndex((item) => Number(item.id) === jobId);
	const currentJob = currentIndex === -1 ? null : state.jobs[currentIndex];

	if (!currentJob) {
		return null;
	}

	const needsFullRender = added || previousJob === null || previousIndex !== currentIndex || jobStructureChanged(previousJob, currentJob);

	return {
		job: currentJob,
		needsFullRender,
	};
}

function removeJobFromState(jobId) {
	const numericId = Number(jobId);
	if (!Number.isFinite(numericId)) {
		return false;
	}

	const index = state.jobs.findIndex((job) => Number(job.id) === numericId);
	if (index === -1) {
		return false;
	}

	state.jobs.splice(index, 1);

	return true;
}

function jobStructureChanged(previous, current) {
	if (!previous || !current) {
		return true;
	}

	return (
		previous.status !== current.status ||
		previous.status_label !== current.status_label ||
		previous.priority !== current.priority ||
		previous.position !== current.position ||
		previous.provider_key !== current.provider_key ||
		previous.provider_name !== current.provider_name ||
		previous.user_id !== current.user_id ||
		previous.user_name !== current.user_name ||
		previous.user_email !== current.user_email ||
		previous.title !== current.title ||
		previous.final_path !== current.final_path ||
		previous.deleted_at !== current.deleted_at ||
		previous.error_text !== current.error_text
	);
}

export { sortJobsInPlace };

// Determine if a job event is recent enough to warrant a toast.
// Strategy:
// 1. If the job object has updated_at or completed/failed timestamp fields, compare with now.
// 2. Otherwise fallback to comparing current time with stream connection time; suppress events received within the first backlog window.
// We treat events as recent if they are within the last 10 seconds relative to now OR they arrived after the stream was opened.
function isRecentJobEvent(event, job) {
	const now = Date.now();
	const STREAM_GRACE_MS = 2000; // ignore backlog toasts during first 2s unless timestamp proves recency
	const RECENT_THRESHOLD_MS = 10000; // 10s window for explicit timestamps

	// Prefer explicit timestamps from job payload
	const tsFields = ['updated_at', 'completed_at', 'failed_at', 'deleted_at', 'created_at'];
	for (const field of tsFields) {
		const raw = job && job[field];
		if (!raw) continue;
		const date = parseIsoDate(raw);
		const t = date?.getTime();
		if (Number.isFinite(t)) {
			if (now - t <= RECENT_THRESHOLD_MS) return true; // clearly recent
			return false; // has timestamp but stale
		}
	}

	// No usable timestamp: fall back to connection timing heuristics
	if (typeof state.jobStreamConnectedAt === 'number' && state.jobStreamConnectedAt > 0) {
		// Suppress events that arrive immediately after connect (likely backlog) within grace period
		if (now - state.jobStreamConnectedAt < STREAM_GRACE_MS) {
			return false;
		}
		// After grace window, allow toasts
		return true;
	}

	// If we cannot determine, be conservative and suppress
	return false;
}
