/**
 * JF Fetch UI entry point.
 *
 * Implements login, provider search, queue management, and admin panels using
 * vanilla JavaScript + Tailwind. The UI interacts with session-protected
 * backend endpoints defined under `public/api/`.
 */

import { state, els, API, providerCatalog } from './modules/context.js';
	import {
		fetchJson,
		toggleElement,
		showToast,
		escapeHtml,
		messageFromError,
		formatRelativeSize,
		formatDuration,
		formatBitrate,
		formatFps,
		formatAudioChannels,
		formatSpeed,
		formatRelativeTime,
		parseIsoDate,
	} from './modules/utils.js';
	import { injectUtilityStyles } from './modules/styles.js';
	import {
		wireStorage,
		loadStorage,
		renderStorage,
		startStorageAutoRefresh,
		stopStorageAutoRefresh,
	} from './modules/storage.js';
	import {
		wireJobs,
		loadJobs,
		renderJobs,
		startJobStream,
		stopJobStream,
	} from './modules/jobs.js';

	const BUILD_VERSION = typeof window.APP_BUILD_VERSION === 'string' && window.APP_BUILD_VERSION !== '' ? window.APP_BUILD_VERSION : null;
	const ASSET_VERSION_CHECK_INTERVAL = 5 * 60 * 1000;
	let assetVersionPollId = null;
	let assetVersionVisibilityHandler = null;
	let assetVersionMismatch = false;

	injectUtilityStyles();
	init();

	function init() {
		initAssetVersionWatcher();
		wireLogin();
		wireDashboard();
		wireSearch();
		wireKraska();
		applyDefaultSearchLimit();
		wireJobs();
		wireAdmin();
		wireSettings();
		wireStorage();
		showLogin();
		hydrateSession();
	}

	function initAssetVersionWatcher() {
		if (!BUILD_VERSION || !els.assetVersionBanner) {
			return;
		}

		const runCheck = async () => {
			if (assetVersionMismatch) {
				return;
			}
			try {
				const response = await fetchJson(`${API.systemVersion}?t=${Date.now()}`, {
					cache: 'no-store',
					headers: {
						Pragma: 'no-cache',
						'Cache-Control': 'no-cache',
					},
				});
				const serverVersion = extractAssetVersion(response);
				if (!serverVersion) {
					return;
				}
				if (serverVersion !== BUILD_VERSION) {
					assetVersionMismatch = true;
					showAssetVersionBanner(serverVersion);
				}
			} catch (error) {
				console.debug('Asset version check failed', error);
			}
		};

		if (els.assetVersionReload) {
			els.assetVersionReload.addEventListener('click', triggerHardReload);
		}

		void runCheck();

		if (assetVersionPollId !== null) {
			window.clearInterval(assetVersionPollId);
		}
		assetVersionPollId = window.setInterval(runCheck, ASSET_VERSION_CHECK_INTERVAL);

		if (!assetVersionVisibilityHandler) {
			assetVersionVisibilityHandler = () => {
				if (!document.hidden) {
					void runCheck();
				}
			};
			document.addEventListener('visibilitychange', assetVersionVisibilityHandler);
		}
	}

	function extractAssetVersion(payload) {
		if (!payload) return null;
		if (typeof payload === 'string') {
			const trimmed = payload.trim();
			return trimmed !== '' ? trimmed : null;
		}
		if (typeof payload.asset_version === 'string' && payload.asset_version !== '') {
			return payload.asset_version;
		}
		if (payload.data && typeof payload.data.asset_version === 'string' && payload.data.asset_version !== '') {
			return payload.data.asset_version;
		}
		return null;
	}

	function showAssetVersionBanner(serverVersion) {
		const banner = els.assetVersionBanner;
		if (!banner) return;
		const messageEl = banner.querySelector('[data-version-message]');
		const serverLabel = serverVersion ? ` (server v${serverVersion})` : '';
		const clientLabel = BUILD_VERSION ? `You are running v${BUILD_VERSION}.` : '';
		const message = `New interface update available${serverLabel}. ${clientLabel} Reload to stay in sync.`.trim();
		if (messageEl) {
			messageEl.textContent = message;
		}
		toggleElement(banner, true, 'flex');
	}

	function triggerHardReload() {
		try {
			const url = new URL(window.location.href);
			url.searchParams.set('_reload', String(Date.now()));
			window.location.replace(url.toString());
		} catch (error) {
			window.location.reload();
		}
	}

	function wireLogin() {
		els.loginForm?.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (!els.loginForm) return;
			toggleElement(els.loginError, false);

			const email = els.loginEmail?.value.trim() ?? '';
			const password = els.loginPassword?.value ?? '';

			if (!email || !password) {
				showFormError('Please provide both email and password.');
				return;
			}

			try {
				const response = await fetchJson(API.login, {
					method: 'POST',
					body: JSON.stringify({ email, password }),
				});

				if (!response?.user) {
					showFormError('Unexpected response from server.');
					return;
				}

				setUser(response.user);
				els.loginForm.reset();
				showToast('Signed in successfully.', 'success');
				enterDashboard();
			} catch (error) {
				showFormError(messageFromError(error));
			}
		});

		els.logoutBtn?.addEventListener('click', async () => {
			try {
				await fetchJson(API.logout, { method: 'POST' });
			} catch (error) {
				console.warn('Logout failed', error);
			}

			resetState();
			stopJobStream();
			showToast('Signed out.', 'info');
			showLogin();
		});
	}

	function wireDashboard() {
		els.tabs.forEach((tab) => {
			tab.addEventListener('click', () => switchView(tab.dataset.view ?? ''));
		});
	}

function wireSearch() {
	if (els.searchLimit instanceof HTMLInputElement) {
		const markEdited = () => {
			els.searchLimit.dataset.userEdited = '1';
		};
		els.searchLimit.addEventListener('input', markEdited);
		els.searchLimit.addEventListener('change', markEdited);
	}

	els.searchForm?.addEventListener('submit', async (event) => {
		event.preventDefault();
		if (!state.user) {
			showToast('Please sign in first.', 'warning');
			return;
		}

		const query = els.searchQuery?.value.trim();
		if (!query) {
			showToast('Enter a search query.', 'warning');
			return;
		}

		const fallbackLimit = Number.isFinite(state.defaultSearchLimit) && state.defaultSearchLimit > 0 ? state.defaultSearchLimit : 50;
		const parsedLimit = Number.parseInt(els.searchLimit?.value ?? String(fallbackLimit), 10) || fallbackLimit;
		const limit = Math.max(1, Math.min(100, parsedLimit));
		if (els.searchLimit instanceof HTMLInputElement) {
			els.searchLimit.value = String(limit);
		}
		const providers = getSelectedProviders();

		toggleElement(els.searchErrors, false);
		toggleElement(els.searchEmpty, false);
		toggleElement(els.searchLoading, true);
		els.searchResults.innerHTML = '';
		els.searchMeta.textContent = '';
		renderSearchWarnings([]);
		state.selectedSearch.clear();
		updateQueueButton();

		try {
			const params = new URLSearchParams({ q: query, limit: String(limit) });
			providers.forEach((key) => params.append('providers[]', key));

			const response = await fetchJson(`${API.search}?${params.toString()}`);
			const results = Array.isArray(response?.data) ? response.data : [];
			state.searchResults = normalizeSearchResults(results);

			renderSearchResults();
			renderSearchMeta(query, state.searchResults.length, providers);
			renderSearchWarnings(Array.isArray(response?.duplicates) ? response.duplicates : []);
			renderSearchErrors(response?.errors ?? []);
		} catch (error) {
			toggleElement(els.searchErrors, true);
			els.searchErrors.innerHTML = `<div class="rounded-lg border border-rose-500/40 bg-rose-500/10 p-3 text-sm text-rose-200">${escapeHtml(
				messageFromError(error)
			)}</div>`;
			state.searchResults = [];
			state.selectedSearch.clear();
			renderSearchResults();
			renderSearchMeta(els.searchQuery?.value ?? '', 0, providers);
			renderSearchWarnings([]);
		} finally {
			toggleElement(els.searchLoading, false);
		}
	});

	els.queueSelectionBtn?.addEventListener('click', queueSelectedResults);
}

function wireKraska() {
	els.kraskaBackBtn?.addEventListener('click', () => {
		if (state.kraska.trail.length <= 1) {
			return;
		}
		const previousIndex = state.kraska.trail.length - 2;
		const crumb = state.kraska.trail[previousIndex];
		if (!crumb) {
			return;
		}
		loadKraskaMenu(crumb.path, { trailIndex: previousIndex });
	});

	els.kraskaHomeBtn?.addEventListener('click', () => {
		loadKraskaMenu('/', { resetTrail: true });
	});

	els.kraskaQueueBtn?.addEventListener('click', queueSelectedKraska);

	els.kraskaRefreshBtn?.addEventListener('click', () => {
		if (state.kraska.loading) return;
		const targetPath = typeof state.kraska.currentPath === 'string' ? state.kraska.currentPath : '/';
		loadKraskaMenu(targetPath, { forceRefresh: true });
	});

	els.kraskaList?.addEventListener('click', (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const optionsTrigger = target.closest('[data-kraska-options]');
		if (optionsTrigger instanceof HTMLElement) {
			const index = Number.parseInt(optionsTrigger.dataset.kraskaOptions ?? '', 10);
			if (Number.isFinite(index)) {
				const item = state.kraska.items[index];
				if (item) {
					event.preventDefault();
					openKraskaOptionsModal(item);
				}
			}
			return;
		}
		const trigger = target.closest('[data-kraska-open]');
		if (!(trigger instanceof HTMLElement)) return;
		const index = Number.parseInt(trigger.dataset.kraskaOpen ?? '', 10);
		if (!Number.isFinite(index)) return;
		const item = state.kraska.items[index];
		if (!item || !item.path) return;
		event.preventDefault();
		loadKraskaMenu(item.path, { pushTrail: true, label: item.label ?? null });
	});

	els.kraskaList?.addEventListener('change', (event) => {
		const target = event.target;
		if (!(target instanceof HTMLInputElement)) return;
		if (!target.matches('input[type="checkbox"][data-kraska-select]')) return;
		const cacheKey = target.dataset.kraskaSelect;
		if (!cacheKey) return;
		if (target.checked) {
			state.kraska.selected.add(cacheKey);
		} else {
			state.kraska.selected.delete(cacheKey);
		}
		updateKraskaQueueButton();
		updateKraskaSelectAllCheckbox();
	});

	els.kraskaBreadcrumbs?.addEventListener('click', (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const button = target.closest('[data-kraska-crumb]');
		if (!(button instanceof HTMLElement)) return;
		const index = Number.parseInt(button.dataset.kraskaCrumb ?? '', 10);
		if (!Number.isFinite(index)) return;
		const crumb = state.kraska.trail[index];
		if (!crumb) return;
		loadKraskaMenu(crumb.path, { trailIndex: index });
	});

	els.kraskaSelectAll?.addEventListener('change', (event) => {
		const target = event.target;
		if (!(target instanceof HTMLInputElement)) return;
		target.indeterminate = false;
		setKraskaSelectAll(Boolean(target.checked));
	});
}

function wireAdmin() {
	els.providerCreateBtn?.addEventListener('click', () => openProviderModal());
	els.providersList?.addEventListener('click', (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const row = target.closest('[data-provider-id]');
		if (!row) return;
		const providerId = Number.parseInt(row.dataset.providerId ?? '0', 10);
		if (!providerId) return;

		if (target.matches('[data-provider-edit]')) {
			const provider = state.providers.find((p) => p.id === providerId);
			if (provider) openProviderModal(provider);
		}

		if (target.matches('[data-provider-delete]')) {
			confirmProviderDelete(providerId);
		}

		if (target.matches('[data-provider-test]')) {
			testProvider(providerId);
		}
	});

	els.auditRefreshBtn?.addEventListener('click', () => loadAudit(true));
	els.auditLoadMore?.addEventListener('click', () => loadAudit(false));

		els.userCreateBtn?.addEventListener('click', () => openUserModal());

		els.usersTable?.addEventListener('click', (event) => {
			const target = event.target;
			if (!(target instanceof HTMLElement)) return;
			const row = target.closest('[data-user-id]');
			if (!row) return;
			const userId = Number.parseInt(row.dataset.userId ?? '0', 10);
			if (!userId) return;

			const editButton = target.closest('[data-user-edit]');
			if (editButton) {
				const user = state.users.find((item) => Number(item.id) === userId);
				if (user) openUserModal(user);
				return;
			}

			const deleteButton = target.closest('[data-user-delete]');
			if (deleteButton) {
				confirmUserDelete(userId);
			}
		});
}

function wireSettings() {
	els.settingsForm?.addEventListener('submit', async (event) => {
		event.preventDefault();
		if (!state.isAdmin) {
			showToast('Only administrators can update settings.', 'warning');
			return;
		}

		await saveSettings();
	});

	// Jellyfin libraries interactions
	const fetchBtn = document.getElementById('jellyfin-libraries-refresh');
	const hideBtn = document.getElementById('jellyfin-libraries-hide');
	const wrapper = document.getElementById('jellyfin-libraries-wrapper');
	const loadingEl = document.getElementById('jellyfin-libraries-loading');
	const errorEl = document.getElementById('jellyfin-libraries-error');
	const selectEl = document.getElementById('jellyfin-libraries-select');
	const libraryIdInput = els.settingsJellyfinLibraryId;

	fetchBtn?.addEventListener('click', async () => {
		if (!state.isAdmin) return;
		if (!wrapper) return;
		wrapper.classList.remove('hidden');
		if (errorEl) {
			errorEl.classList.add('hidden');
			errorEl.textContent = '';
		}
		if (loadingEl) loadingEl.classList.remove('hidden');
		if (selectEl) selectEl.innerHTML = '';
		try {
			const response = await fetchJson(API.jellyfinLibraries);
			const libs = Array.isArray(response?.data) ? response.data : [];
			if (selectEl) {
				selectEl.innerHTML = libs
					.map((lib) => {
						const id = String(lib.id ?? '');
						const name = String(lib.name ?? id);
						const type = lib.collection_type ? ` (${lib.collection_type})` : '';
						const selected = libraryIdInput && libraryIdInput.value === id ? 'selected' : '';
						return `<option value="${id}" ${selected}>${escapeHtml(name + type)} – ${escapeHtml(id)}</option>`;
					})
					.join('');
			}
		} catch (error) {
			if (errorEl) {
				errorEl.textContent = messageFromError(error);
				errorEl.classList.remove('hidden');
			}
		} finally {
			if (loadingEl) loadingEl.classList.add('hidden');
		}
	});

	selectEl?.addEventListener('change', () => {
		if (!(selectEl instanceof HTMLSelectElement) || !libraryIdInput) return;
		const option = selectEl.selectedOptions[0];
		if (option) {
			libraryIdInput.value = option.value;
			showToast('Library ID selected.', 'success');
		}
	});

	hideBtn?.addEventListener('click', () => {
		wrapper?.classList.add('hidden');
	});
}

async function loadSettings(force = false) {
	if (!state.user || !state.isAdmin) {
		return;
	}

	if (!force && state.settings !== null) {
		renderSettings();
		return;
	}

	state.settingsLoading = true;
	state.settingsError = null;
	renderSettings();

	try {
		const response = await fetchJson(API.settings);
		state.settings = response?.data ?? null;
		setDefaultSearchLimit(response?.data?.app?.default_search_limit);
	} catch (error) {
		state.settings = null;
		state.settingsError = messageFromError(error);
		showToast(state.settingsError, 'error');
	} finally {
		state.settingsLoading = false;
		renderSettings();
	}
}

async function saveSettings() {
	if (!els.settingsForm) return;

	const maxDownloadsInput = els.settingsMaxDownloads?.value ?? '';
	const parsedMaxDownloads = Number.parseInt(maxDownloadsInput, 10);
	const maxDownloads = Number.isNaN(parsedMaxDownloads) ? null : parsedMaxDownloads;

	const minFreeSpaceInput = els.settingsMinFreeSpace?.value ?? '';
	const parsedMinFreeSpace = Number.parseFloat(minFreeSpaceInput);
	const minFreeSpace = Number.isNaN(parsedMinFreeSpace) ? null : parsedMinFreeSpace;

	const kraskaTtlInput = els.settingsKraskaMenuCacheTtl?.value ?? '';
	const parsedKraskaTtl = Number.parseFloat(kraskaTtlInput);
	const kraskaTtlDays = Number.isNaN(parsedKraskaTtl) ? null : Math.max(parsedKraskaTtl, 0);
	const kraskaTtlSeconds = kraskaTtlDays === null ? null : Math.round(kraskaTtlDays * 86400);

	const kraskaDebugEnabled = els.settingsKraskaDebugEnabled?.checked ?? false;

	const payload = {
		app: {
			base_url: (els.settingsBaseUrl?.value ?? '').trim(),
			max_active_downloads: maxDownloads,
			min_free_space_gb: minFreeSpace,
			default_search_limit: Number.parseInt(els.settingsDefaultSearchLimit?.value ?? '', 10) || null,
		},
		paths: {
			downloads: (els.settingsDownloadsPath?.value ?? '').trim(),
			library: (els.settingsLibraryPath?.value ?? '').trim(),
		},
		jellyfin: {
			url: (els.settingsJellyfinUrl?.value ?? '').trim(),
			api_key: (els.settingsJellyfinApiKey?.value ?? '').trim(),
			library_id: (els.settingsJellyfinLibraryId?.value ?? '').trim(),
		},
		providers: {
			kraska_menu_cache_ttl_seconds: kraskaTtlSeconds,
			kraska_debug_enabled: kraskaDebugEnabled,
		},
	};

	state.settingsSaving = true;
	state.settingsError = null;
	renderSettings();

	try {
		const response = await fetchJson(API.settings, {
			method: 'PUT',
			body: JSON.stringify(payload),
		});
		state.settings = response?.data ?? state.settings;
		setDefaultSearchLimit(state.settings?.app?.default_search_limit, { force: true });
		showToast('Settings saved.', 'success');
	} catch (error) {
		const message = messageFromError(error);
		if (error?.details && typeof error.details === 'object') {
			const details = Object.values(error.details)
				.map((value) => String(value))
				.filter(Boolean);
			state.settingsError = details.length > 0 ? details.join(' ') : message;
		} else {
			state.settingsError = message;
		}
		showToast(state.settingsError ?? 'Failed to save settings.', 'error');
	} finally {
		state.settingsSaving = false;
		renderSettings();
	}
}

function renderSettings() {
	const isLoading = state.settingsLoading;
	const isSaving = state.settingsSaving;
	const errorMessage = state.settingsError;
	const settings = state.settings;

	if (els.settingsLoading) {
		toggleElement(els.settingsLoading, isLoading);
	}

	if (els.settingsError) {
		if (errorMessage) {
			els.settingsError.textContent = errorMessage;
			toggleElement(els.settingsError, true);
		} else {
			toggleElement(els.settingsError, false);
		}
	}

	const inputs = [
		els.settingsBaseUrl,
		els.settingsMaxDownloads,
		els.settingsMinFreeSpace,
		els.settingsDefaultSearchLimit,
		els.settingsKraskaMenuCacheTtl,
		els.settingsKraskaDebugEnabled,
		els.settingsDownloadsPath,
		els.settingsLibraryPath,
		els.settingsJellyfinUrl,
		els.settingsJellyfinApiKey,
		els.settingsJellyfinLibraryId,
	].filter((input) => input instanceof HTMLInputElement);

	inputs.forEach((input) => {
		if (!input) return;
		input.disabled = isLoading || isSaving;
	});

	if (els.settingsSaveBtn) {
		els.settingsSaveBtn.disabled = isLoading || isSaving;
		els.settingsSaveBtn.textContent = isSaving ? 'Saving…' : 'Save changes';
	}

	if (settings && !isLoading && !isSaving) {
		if (els.settingsBaseUrl) {
			els.settingsBaseUrl.value = settings?.app?.base_url ?? '';
		}
		if (els.settingsMaxDownloads) {
			const maxDownloads = settings?.app?.max_active_downloads;
			els.settingsMaxDownloads.value = maxDownloads === null || maxDownloads === undefined ? '' : String(maxDownloads);
		}
		if (els.settingsMinFreeSpace) {
			const minFreeSpace = settings?.app?.min_free_space_gb;
			els.settingsMinFreeSpace.value = minFreeSpace === null || Number.isNaN(minFreeSpace) ? '' : String(minFreeSpace);
		}
		if (els.settingsDefaultSearchLimit) {
			const dsl = settings?.app?.default_search_limit;
			els.settingsDefaultSearchLimit.value = dsl === null || dsl === undefined ? '' : String(dsl);
		}
		if (els.settingsKraskaMenuCacheTtl) {
			const ttlSeconds = settings?.providers?.kraska_menu_cache_ttl_seconds;
			if (ttlSeconds === null || ttlSeconds === undefined) {
				els.settingsKraskaMenuCacheTtl.value = '';
			} else {
				const ttlDays = ttlSeconds / 86400;
				const rounded = Math.round(ttlDays * 10) / 10;
				els.settingsKraskaMenuCacheTtl.value = Number.isFinite(rounded) ? String(rounded) : '';
			}
		}
		if (els.settingsKraskaDebugEnabled) {
			els.settingsKraskaDebugEnabled.checked = settings?.providers?.kraska_debug_enabled === true;
		}
		if (els.settingsDownloadsPath) {
			els.settingsDownloadsPath.value = settings?.paths?.downloads ?? '';
		}
		if (els.settingsLibraryPath) {
			els.settingsLibraryPath.value = settings?.paths?.library ?? '';
		}
		if (els.settingsJellyfinUrl) {
			els.settingsJellyfinUrl.value = settings?.jellyfin?.url ?? '';
		}
		if (els.settingsJellyfinApiKey) {
			els.settingsJellyfinApiKey.value = settings?.jellyfin?.api_key ?? '';
		}
		if (els.settingsJellyfinLibraryId) {
			els.settingsJellyfinLibraryId.value = settings?.jellyfin?.library_id ?? '';
		}
	}
}

function showLogin() {
	stopJobStream();
	stopStorageAutoRefresh();
	toggleElement(els.loginView, true, 'flex');
	toggleElement(els.dashboardView, false);
	toggleElement(els.logoutBtn, false);
	toggleElement(els.userPill, false);
	state.isAdmin = false;
	state.jobNotifications.clear();
	hideAdminTabs();
}

async function hydrateSession() {
	try {
		const response = await fetchJson(API.session);
		if (response?.defaults && Object.prototype.hasOwnProperty.call(response.defaults, 'search_limit')) {
			setDefaultSearchLimit(response.defaults.search_limit, { force: true });
		}
		const user = response?.user;
		if (user) {
			setUser(user);
			enterDashboard();
			return;
		}
	} catch (error) {
		if (!(error?.status === 401 || error?.status === 403)) {
			console.warn('Failed to hydrate session', error);
		}
	}

	resetState();
	showLogin();
}

function enterDashboard() {
	toggleElement(els.loginView, false);
	toggleElement(els.dashboardView, true, 'flex');
	toggleElement(els.logoutBtn, true, 'inline-flex');
	toggleElement(els.userPill, true, 'inline-flex');
	switchView('search');
	refreshAllData();
}

function refreshAllData() {
	const tasks = [
		loadProviders(),
		loadJobs(),
	    loadStats(),
		loadStorage(),
	];

	if (state.isAdmin) {
		tasks.push(loadUsers());
		tasks.push(loadSettings());
	}

	Promise.all(tasks).finally(() => {
		startStorageAutoRefresh();
		startJobStream();
		if (state.isAdmin) {
			loadProviderStatuses();
		}
        loadStats();
	});
}

function resetState() {
	stopStorageAutoRefresh();
	state.user = null;
	state.providers = [];
	state.users = [];
	state.searchResults = [];
	state.selectedSearch.clear();
	state.jobs = [];
	state.storage = [];
	state.storageUpdatedAt = null;
	state.isAdmin = false;
	state.usersLoading = false;
	state.jobNotifications.clear();
	state.storageIntervalId = null;
	state.auditLogs = [];
	state.auditCursor = null;
	state.auditLoading = false;
	state.settings = null;
	state.settingsLoading = false;
	state.settingsSaving = false;
	state.settingsError = null;
	state.defaultSearchLimit = 50;
	state.kraska.currentPath = '/';
	state.kraska.title = null;
	state.kraska.items = [];
	state.kraska.trail = [];
	state.kraska.selected = new Set();
	state.kraska.loading = false;
	state.kraska.error = null;
	state.kraska.cache = null;
	state.kraska.selectedItem = null;
	state.kraska.variants = [];
	state.kraska.variantsLoading = false;
	state.kraska.variantsError = null;
	state.kraska.variantQueueing = false;
	applyDefaultSearchLimit(true);
	renderProviders();
	renderSearchResults();
	renderKraskaMenu();
	renderJobs();
	renderStorage();
	renderStats();
	renderSearchWarnings([]);
	renderUsers();
	renderAudit();
	renderSettings();
}

// Stats (jobs aggregate)
async function loadStats() {
	if (!state.user) return;
	const container = document.getElementById('stats-content');
	const errorEl = document.getElementById('stats-error');
	const loadingEl = document.getElementById('stats-loading');
	const metaEl = document.getElementById('stats-meta');
	if (!container || !errorEl || !loadingEl) return;
	loadingEl.classList.remove('hidden');
	errorEl.classList.add('hidden');
	try {
		const response = await fetchJson(API.jobsStats);
		state.stats = response?.data ?? null;
		renderStats();
		if (metaEl) metaEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
	} catch (error) {
		if (errorEl) {
			errorEl.textContent = messageFromError(error);
			errorEl.classList.remove('hidden');
		}
	} finally {
		loadingEl.classList.add('hidden');
	}
}

document.getElementById('stats-refresh-btn')?.addEventListener('click', () => loadStats());

function renderStats() {
	const container = document.getElementById('stats-content');
	if (!container) return;
	const stats = state.stats;
	if (!stats) {
		container.innerHTML = '<div class="text-sm text-slate-500">No stats yet.</div>';
		return;
	}
	const rows = [];
	const humanBytes = (bytes) => {
		if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
		const units = ['B','KB','MB','GB','TB','PB'];
		let i = 0;
		let value = bytes;
		while (value >= 1024 && i < units.length - 1) { value /= 1024; i++; }
		return value.toFixed(value >= 10 || i === 0 ? 0 : 1) + ' ' + units[i];
	};
	const push = (label, value) => rows.push(`<div class="flex items-center justify-between text-xs"><span class="text-slate-400">${escapeHtml(label)}</span><span class="font-medium text-slate-200">${escapeHtml(String(value))}</span></div>`);
	push('Total jobs', stats.total_jobs);
	push('Completed', stats.completed_jobs);
	push('Active', stats.active_jobs);
	push('Queued', stats.queued_jobs);
	push('Paused', stats.paused_jobs);
	push('Canceled', stats.canceled_jobs);
	push('Failed', stats.failed_jobs);
	push('Deleted', stats.deleted_jobs);
	push('Distinct users', stats.distinct_users);
	push('Success rate', stats.success_rate_pct !== null ? stats.success_rate_pct + '%' : '—');
	push('Total downloaded', humanBytes(stats.total_bytes_downloaded));
	push('Total download time', formatDuration(stats.total_download_duration_seconds));
	push('Avg download time', stats.avg_download_duration_seconds !== null ? formatDuration(stats.avg_download_duration_seconds) : '—');
	container.innerHTML = `<div class="grid gap-1">${rows.join('')}</div>`;
}

function setDefaultSearchLimit(limit, { force = false } = {}) {
	const numeric = Number.parseInt(String(limit ?? ''), 10);
	if (!Number.isFinite(numeric) || numeric <= 0) {
		return;
	}
	if (!force && state.defaultSearchLimit === numeric) {
		return;
	}
	state.defaultSearchLimit = numeric;
	applyDefaultSearchLimit(force);
}

function applyDefaultSearchLimit(force = false) {
	const input = els.searchLimit;
	if (!(input instanceof HTMLInputElement)) {
		return;
	}
	const defaultLimit = Number.isFinite(state.defaultSearchLimit) && state.defaultSearchLimit > 0 ? state.defaultSearchLimit : 50;
	if (!force && input.dataset.userEdited === '1') {
		return;
	}
	input.value = String(defaultLimit);
	delete input.dataset.userEdited;
}

function setUser(user) {
	state.user = user;
	state.isAdmin = (user?.role ?? '') === 'admin';
	updateUserPill();
	if (state.isAdmin) {
		showAdminTabs();
	} else {
		hideAdminTabs();
	}
}

function updateUserPill() {
	if (!els.userPill || !state.user) return;
	els.userPill.innerHTML = `
		<div class="flex flex-col">
			<span class="text-sm font-semibold">${escapeHtml(state.user.name ?? state.user.email)}</span>
			<span class="text-xs uppercase tracking-wide text-slate-400">${escapeHtml(state.user.role)}</span>
		</div>
	`;
}

function showAdminTabs() {
	toggleElement(els.providersTab, true, 'inline-flex');
	toggleElement(els.settingsTab, true, 'inline-flex');
	toggleElement(els.usersTab, true, 'inline-flex');
	toggleElement(els.auditTab, true, 'inline-flex');
}

function hideAdminTabs() {
	toggleElement(els.providersTab, false);
	toggleElement(els.settingsTab, false);
	toggleElement(els.usersTab, false);
	toggleElement(els.auditTab, false);
	toggleElement(els.usersError, false);
	toggleElement(els.usersEmpty, false);
	toggleElement(els.auditError, false);
	toggleElement(els.auditEmpty, false);
	toggleElement(els.auditLoadMore, false);
	toggleElement(els.settingsError, false);
	toggleElement(els.settingsLoading, false);
}

function switchView(view) {
	state.currentView = view;
	els.tabs.forEach((tab) => {
		const isActive = tab.dataset.view === view;
		tab.classList.toggle('is-active', isActive);
		if (isActive) {
			tab.setAttribute('aria-current', 'page');
		} else {
			tab.removeAttribute('aria-current');
		}
	});

	els.panels.forEach((panel) => {
		const isMatch = panel.dataset.panel === view;
		toggleElement(panel, isMatch);
	});

	if (view === 'users' && state.isAdmin) {
		loadUsers();
	}

	if (view === 'audit' && state.isAdmin && state.auditLogs.length === 0) {
		loadAudit(true);
	}

	if (view === 'settings' && state.isAdmin) {
		loadSettings(true);
	}

	if (view === 'kraska') {
		ensureKraskaLoaded();
	}
}

function ensureKraskaLoaded() {
	if (!state.user) return;
	if (!state.kraska.items || state.kraska.items.length === 0) {
		if (!state.kraska.loading) {
			loadKraskaMenu('/', { resetTrail: true });
		}
	} else {
		renderKraskaMenu();
	}
}

async function loadKraskaMenu(path = '/', options = {}) {
	if (!state.user) {
		showToast('Please sign in first.', 'warning');
		return;
	}
	const normalizedPath = normalizeKraskaPath(path);
	const { pushTrail = false, resetTrail = false, trailIndex = null, label = null, forceRefresh = false } = options;
	if (state.kraska.loading) {
		return;
	}
	state.kraska.loading = true;
	state.kraska.error = null;
	state.kraska.cache = null;
	if (!pushTrail) {
		state.kraska.selected.clear();
	}
	renderKraskaMenu();

	try {
		const params = new URLSearchParams({ path: normalizedPath });
		if (forceRefresh) {
			params.set('refresh', '1');
		}
		const response = await fetchJson(`${API.kraskaMenu}?${params.toString()}`);
		const payload = response?.data ?? {};
		const cacheMeta = normalizeKraskaCacheMeta(response?.cache ?? null);
		const items = Array.isArray(payload?.items) ? payload.items : [];
		state.kraska.currentPath = payload?.path ?? normalizedPath;
		state.kraska.title = payload?.title ?? null;
		state.kraska.items = normalizeKraskaItems(items);
		state.kraska.selected = new Set();
		state.kraska.cache = cacheMeta;
		if (forceRefresh) {
			showToast('Menu refreshed from source.', 'success');
		}

		const resolvedLabel = state.kraska.title ?? label ?? (state.kraska.currentPath === '/' ? 'Browse' : state.kraska.currentPath);
		const previousTrail = Array.isArray(state.kraska.trail) ? state.kraska.trail.slice() : [];
		let nextTrail;
		if (resetTrail || previousTrail.length === 0) {
			nextTrail = [{ path: state.kraska.currentPath, label: resolvedLabel }];
		} else if (typeof trailIndex === 'number' && Number.isFinite(trailIndex) && trailIndex >= 0) {
			nextTrail = previousTrail.slice(0, trailIndex + 1);
			nextTrail[nextTrail.length - 1] = { path: state.kraska.currentPath, label: resolvedLabel };
		} else if (pushTrail) {
			nextTrail = previousTrail.concat({ path: state.kraska.currentPath, label: resolvedLabel });
		} else {
			nextTrail = previousTrail.slice();
			nextTrail[nextTrail.length - 1] = { path: state.kraska.currentPath, label: resolvedLabel };
		}
		state.kraska.trail = nextTrail;
	} catch (error) {
		state.kraska.error = messageFromError(error);
		state.kraska.items = [];
		state.kraska.cache = null;
	} finally {
		state.kraska.loading = false;
		renderKraskaMenu();
	}
}

function renderKraskaMenu() {
	if (els.kraskaLoading) {
		toggleElement(els.kraskaLoading, state.kraska.loading);
	}

	if (els.kraskaError) {
		if (state.kraska.error) {
			els.kraskaError.textContent = state.kraska.error;
			toggleElement(els.kraskaError, true);
		} else {
			toggleElement(els.kraskaError, false);
		}
	}

	const hasItems = Array.isArray(state.kraska.items) && state.kraska.items.length > 0;
	if (els.kraskaEmpty) {
		const showEmpty = !state.kraska.loading && !state.kraska.error && !hasItems;
		toggleElement(els.kraskaEmpty, showEmpty);
	}

	if (els.kraskaList) {
		if (!hasItems) {
			els.kraskaList.innerHTML = '';
		} else {
			els.kraskaList.innerHTML = state.kraska.items
				.map((item, index) => renderKraskaItem(item, index))
				.join('');
		}
	}

	renderKraskaBreadcrumbs();
	updateKraskaBackButton();
	updateKraskaQueueButton();
	updateKraskaSelectAllCheckbox();
	renderKraskaCacheMeta();
	updateKraskaRefreshButton();
	if (els.kraskaHomeBtn) {
		const atRoot = normalizeKraskaPath(state.kraska.currentPath ?? '/') === '/';
		els.kraskaHomeBtn.toggleAttribute('disabled', atRoot);
	}
}

function renderKraskaItem(item, index) {
	if ((item.queueMode === 'branch' || item.type === 'dir') && item.path) {
		const summary = item.summary ? `<p class="text-sm text-slate-400 line-clamp-2">${escapeHtml(item.summary)}</p>` : '';
		const metaSection = renderKraskaMetaChips(item);
		const checked = item.selectable && state.kraska.selected.has(item.cacheKey) ? 'checked' : '';
		const checkbox = item.selectable
			? `<input type="checkbox" class="mt-1 h-5 w-5 rounded border-slate-700 bg-slate-950 text-brand-500 focus:ring-brand-500/60" data-kraska-select="${escapeHtml(item.cacheKey)}" ${checked} />`
			: '';
		const queueHint = item.selectable
			? '<span class="text-xs text-slate-400">Queue adds every item inside this folder.</span>'
			: (item.meta?.pagination ? '<span class="text-xs text-slate-500">Opens the next page.</span>' : '');
		const folderBadge = item.meta?.pagination ? 'Next page' : 'Directory';
		const infoLink = `
			<a href="#" data-kraska-open="${index}" class="block rounded-xl px-2 py-1 text-left transition hover:bg-slate-800/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40">
				<div class="flex flex-wrap items-center gap-2">
					<h4 class="text-lg font-semibold text-slate-100">${escapeHtml(item.label ?? 'Untitled')}</h4>
					<span class="rounded-full border border-slate-800/70 bg-slate-950/60 px-2 py-0.5 text-xs uppercase tracking-wide text-slate-300">${escapeHtml(folderBadge)}</span>
				</div>
				${summary}
				${metaSection}
			</a>
		`;
		return `
			<li class="group rounded-2xl border border-slate-800/70 bg-slate-900/60 p-4 transition hover:border-brand-500/40">
				<div class="flex items-start gap-4">
					${checkbox}
					<div class="flex-1 space-y-3">
						${infoLink}
						<div class="flex flex-wrap items-center gap-3">
							<button type="button" data-kraska-open="${index}" class="inline-flex items-center gap-2 rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:border-brand-400/60 hover:text-brand-200">
								<span>Open</span>
							</button>
							${queueHint}
						</div>
					</div>
					<div class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-800/60 text-brand-300">📁</div>
				</div>
			</li>
		`;
	}

	const checked = state.kraska.selected.has(item.cacheKey) ? 'checked' : '';
	const summary = item.summary ? `<p class="text-sm text-slate-400 line-clamp-2">${escapeHtml(item.summary)}</p>` : '';
	const metaSection = renderKraskaMetaChips(item);
	const actionButtons = renderKraskaActionButtons(item, index);
	const actionsMarkup = actionButtons.length > 0
		? `<div class="flex flex-wrap gap-2">${actionButtons.join('')}</div>`
		: '';
	const thumb = item.art?.thumb ?? item.art?.poster ?? item.art?.fanart ?? null;
	const thumbnail = thumb ? `<img src="${escapeHtml(thumb)}" alt="${escapeHtml(item.label ?? 'Artwork')}" class="h-16 w-16 rounded-lg object-cover" loading="lazy" />` : '';

	return `
		<li class="group rounded-2xl border border-slate-800/70 bg-slate-900/60 p-4 transition hover:border-brand-500/40">
			<div class="flex items-start gap-4">
				<input type="checkbox" class="mt-1 h-5 w-5 rounded border-slate-700 bg-slate-950 text-brand-500 focus:ring-brand-500/60" data-kraska-select="${escapeHtml(item.cacheKey)}" ${checked} />
				<div class="flex-1 space-y-2">
					<div class="flex flex-wrap items-center gap-2">
						<h4 class="text-lg font-semibold text-slate-100">${escapeHtml(item.label ?? 'Untitled')}</h4>
						<span class="rounded-full border border-slate-800/70 bg-slate-950/60 px-2 py-0.5 text-xs uppercase tracking-wide text-slate-300">${escapeHtml(item.type ?? 'video')}</span>
						${item.meta?.quality ? `<span class="text-xs text-slate-400">${escapeHtml(String(item.meta.quality))}</span>` : ''}
					</div>
					${summary}
					${metaSection}
					${actionsMarkup}
				</div>
				${thumbnail}
			</div>
		</li>
	`;
}

function renderKraskaActionButtons(item, index) {
	const buttons = [];
	if (!item.selectable || item.queueMode === 'branch') {
		return buttons;
	}
	if (item.ident || item.path || (Array.isArray(item.meta?.variants) && item.meta.variants.length > 0)) {
		buttons.push(`
			<button type="button" data-kraska-options="${index}" class="inline-flex items-center gap-2 rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:border-brand-400/60 hover:text-brand-100">
				<span>Download options</span>
			</button>
		`);
	}
	return buttons;
}

function formatKraskaCacheAge(meta) {
	if (!meta) return '';
	if (meta.fetched_at) {
		return formatRelativeTime(meta.fetched_at);
	}
	if (meta.age_seconds !== null && meta.age_seconds !== undefined) {
		return formatCacheAgeFromSeconds(meta.age_seconds);
	}
	return '';
}

function formatCacheAgeFromSeconds(seconds) {
	const value = Number(seconds);
	if (!Number.isFinite(value) || value <= 0) {
		return 'Just now';
	}
	if (value < 60) {
		return `${Math.round(value)} s ago`;
	}
	if (value < 3600) {
		const mins = Math.round(value / 60);
		return `${mins} min${mins === 1 ? '' : 's'} ago`;
	}
	if (value < 86400) {
		const hours = Math.round(value / 3600);
		return `${hours} h${hours === 1 ? '' : 's'} ago`;
	}
	const days = Math.round(value / 86400);
	return `${days} d${days === 1 ? '' : 's'} ago`;
}

function renderKraskaMetaChips(item) {
	const meta = item.meta ?? {};
	const chips = [];
	if (meta.quality && !chips.includes(meta.quality)) chips.push(String(meta.quality));
	const duration = Number(meta.duration_seconds);
	if (Number.isFinite(duration) && duration > 0) chips.push(formatDuration(duration));
	if (Array.isArray(meta.languages) && meta.languages.length > 0) chips.push(meta.languages.join('/'));
	const year = Number(meta.year);
	if (Number.isFinite(year) && year > 0) chips.push(String(Math.trunc(year)));
	const rating = Number(meta.rating);
	if (Number.isFinite(rating)) chips.push(`${rating.toFixed(1)}★`);
	const audioParts = [];
	if (meta.audio_codec) audioParts.push(String(meta.audio_codec));
	if (meta.audio_channels) audioParts.push(formatAudioChannels(meta.audio_channels));
	if (audioParts.length > 0) chips.push(audioParts.join(' '));
	if (meta.fps) chips.push(`${meta.fps} fps`);

	if (chips.length === 0) {
		return '';
	}

	return `
		<div class="flex flex-wrap gap-2 text-xs text-slate-300">
			${chips
				.map((chip) => `<span class="rounded-full border border-slate-800/60 bg-slate-900/40 px-2 py-0.5">${escapeHtml(String(chip))}</span>`)
				.join('')}
		</div>
	`;
}

function renderKraskaBreadcrumbs() {
	if (!els.kraskaBreadcrumbs) return;
	if (!Array.isArray(state.kraska.trail) || state.kraska.trail.length === 0) {
		els.kraskaBreadcrumbs.innerHTML = '';
		return;
	}
	const fragments = state.kraska.trail.map((crumb, index) => {
		const label = crumb?.label ?? crumb?.path ?? 'Browse';
		if (index === state.kraska.trail.length - 1) {
			return `<span class="text-slate-100">${escapeHtml(label)}</span>`;
		}
		return `<button type="button" data-kraska-crumb="${index}" class="text-sm font-medium text-brand-300 hover:text-brand-200">${escapeHtml(label)}</button>`;
	});
	els.kraskaBreadcrumbs.innerHTML = fragments.join('<span class="text-slate-600">/</span>');
}

function normalizeKraskaCacheMeta(meta) {
	if (!meta || typeof meta !== 'object') {
		return null;
	}

	const fetchedAtRaw = typeof meta.fetched_at === 'string' ? meta.fetched_at.trim() : '';
	const ageRaw = Number(meta.age_seconds ?? null);
	const ttlRaw = Number(meta.ttl_seconds ?? null);

	return {
		hit: Boolean(meta.hit),
		fetched_at: fetchedAtRaw !== '' ? fetchedAtRaw : null,
		age_seconds: Number.isFinite(ageRaw) && ageRaw >= 0 ? ageRaw : null,
		ttl_seconds: Number.isFinite(ttlRaw) && ttlRaw > 0 ? ttlRaw : null,
		refreshable: meta.refreshable !== undefined ? Boolean(meta.refreshable) : null,
	};
}

function renderKraskaCacheMeta() {
	if (!els.kraskaCacheMeta) return;
	const meta = state.kraska.cache;
	if (!meta) {
		els.kraskaCacheMeta.textContent = '';
		delete els.kraskaCacheMeta.dataset.variant;
		toggleElement(els.kraskaCacheMeta, false);
		return;
	}

	const variant = meta.hit ? 'cached' : 'fresh';
	const label = meta.hit ? 'Cached result' : 'Fresh result';
	const ageLabel = formatKraskaCacheAge(meta);
	const text = ageLabel ? `${label} • ${ageLabel}` : label;
	els.kraskaCacheMeta.textContent = text;
	els.kraskaCacheMeta.dataset.variant = variant;
	toggleElement(els.kraskaCacheMeta, true, 'inline-flex');
}

function updateKraskaRefreshButton() {
	if (!els.kraskaRefreshBtn) return;
	if (state.kraska.loading) {
		els.kraskaRefreshBtn.setAttribute('disabled', 'true');
		els.kraskaRefreshBtn.classList.add('opacity-60', 'cursor-not-allowed');
	} else {
		els.kraskaRefreshBtn.removeAttribute('disabled');
		els.kraskaRefreshBtn.classList.remove('opacity-60', 'cursor-not-allowed');
	}
}

function updateKraskaBackButton() {
	if (!els.kraskaBackBtn) return;
	const canGoBack = Array.isArray(state.kraska.trail) && state.kraska.trail.length > 1;
	els.kraskaBackBtn.toggleAttribute('disabled', !canGoBack);
}

function updateKraskaQueueButton() {
	if (!els.kraskaQueueBtn) return;
	const count = state.kraska.selected.size;
	const label = `Add ${count} item${count === 1 ? '' : 's'} to queue`;
	if (count > 0) {
		els.kraskaQueueBtn.textContent = label;
	}
	toggleElement(els.kraskaQueueBtn, count > 0, 'inline-flex');
	if (count === 0) {
		els.kraskaQueueBtn.textContent = 'Add selected to queue';
	}
	if (state.isQueueSubmitting) {
		els.kraskaQueueBtn.setAttribute('disabled', 'true');
	} else {
		els.kraskaQueueBtn.removeAttribute('disabled');
	}
}

function updateKraskaSelectAllCheckbox() {
	const selectAll = els.kraskaSelectAll;
	if (!selectAll) return;

	const selectableKeys = getSelectableKraskaKeys();
	if (selectableKeys.length === 0) {
		selectAll.checked = false;
		selectAll.indeterminate = false;
		selectAll.setAttribute('disabled', 'true');
		return;
	}

	selectAll.removeAttribute('disabled');
	let selectedCount = 0;
	for (const key of selectableKeys) {
		if (state.kraska.selected.has(key)) {
			selectedCount++;
		}
	}

	if (selectedCount === 0) {
		selectAll.checked = false;
		selectAll.indeterminate = false;
		return;
	}

	if (selectedCount === selectableKeys.length) {
		selectAll.checked = true;
		selectAll.indeterminate = false;
		return;
	}

	selectAll.checked = false;
	selectAll.indeterminate = true;
}

function setKraskaSelectAll(checked) {
	const keys = getSelectableKraskaKeys();
	const keySet = new Set(keys);
	state.kraska.selected = checked ? keySet : new Set();

	if (els.kraskaList) {
		els.kraskaList.querySelectorAll('input[type="checkbox"][data-kraska-select]').forEach((element) => {
			if (!(element instanceof HTMLInputElement)) return;
			const key = element.dataset.kraskaSelect ?? '';
			element.checked = checked && keySet.has(key);
		});
	}

	updateKraskaQueueButton();
	updateKraskaSelectAllCheckbox();
}

function getSelectableKraskaKeys() {
	if (!Array.isArray(state.kraska.items)) {
		return [];
	}

	return state.kraska.items
		.filter((item) => item && typeof item.cacheKey === 'string' && item.cacheKey !== '' && item.selectable !== false)
		.map((item) => String(item.cacheKey));
}

async function queueSelectedKraska() {
	if (state.isQueueSubmitting || state.kraska.selected.size === 0) return;
	const selections = Array.from(state.kraska.selected)
		.map((cacheKey) => state.kraska.items.find((item) => item.cacheKey === cacheKey))
		.filter(Boolean);

	const directEntries = [];
	const branchEntries = [];
	for (const entry of selections) {
		if (entry.queueMode === 'branch' && entry.path) {
			branchEntries.push(entry);
		} else if (entry.ident) {
			directEntries.push(entry);
		}
	}

	if (directEntries.length === 0 && branchEntries.length === 0) {
		showToast('Nothing to queue.', 'warning');
		state.kraska.selected.clear();
		updateKraskaQueueButton();
		updateKraskaSelectAllCheckbox();
		return;
	}

	state.isQueueSubmitting = true;
	if (els.kraskaQueueBtn) {
		els.kraskaQueueBtn.setAttribute('disabled', 'true');
		els.kraskaQueueBtn.textContent = branchEntries.length > 0 ? 'Collecting…' : 'Queuing…';
	}

	try {
		const queueMap = new Map();
		const baseTrail = buildKraskaNormalizedTrail();
		const addQueueItem = (provider, externalId, title, metadata) => {
			const key = `${provider}:${externalId}`;
			if (!queueMap.has(key)) {
				queueMap.set(key, {
					provider,
					external_id: externalId,
					title: title && title.trim() !== '' ? title : `${provider.toUpperCase()} ${externalId}`,
					...(metadata && Object.keys(metadata).length > 0 ? { metadata } : {}),
				});
			}
		};

		for (const item of directEntries) {
			const provider = item.provider ?? 'kraska';
			const title = item.label ?? item.summary ?? 'Untitled';
			const metadata = buildKraskaMetadataForItem(item, { baseTrail });
			addQueueItem(provider, item.ident, title, metadata);
		}

		for (const branch of branchEntries) {
			const collected = await collectKraskaBranchItems(branch.path);
			const branchTrail = appendKraskaTrail(baseTrail, {
				label: typeof branch.label === 'string' ? branch.label : null,
				path: typeof branch.path === 'string' ? branch.path : null,
			});
			for (const item of collected) {
				if (!item.ident) continue;
				const provider = item.provider ?? 'kraska';
				const title = item.label ?? item.summary ?? branch.label ?? 'Untitled';
				const metadata = buildKraskaMetadataForItem(item, {
					baseTrail,
					trailOverride: branchTrail,
					branch,
				});
				addQueueItem(provider, item.ident, title, metadata);
			}
		}

		const payloadItems = Array.from(queueMap.values());
		if (payloadItems.length === 0) {
			showToast('Nothing to queue.', 'warning');
			state.kraska.selected.clear();
			return;
		}

		if (els.kraskaQueueBtn) {
			els.kraskaQueueBtn.textContent = `Queuing ${payloadItems.length}…`;
		}

		await fetchJson(API.queue, {
			method: 'POST',
			body: JSON.stringify({ items: payloadItems }),
		});
		showToast(`Queued ${payloadItems.length} item${payloadItems.length === 1 ? '' : 's'}.`, 'success');
		state.kraska.selected.clear();
		updateKraskaSelectAllCheckbox();
		loadJobs();
	} catch (error) {
		showToast(messageFromError(error), 'error');
	} finally {
		state.isQueueSubmitting = false;
		if (els.kraskaQueueBtn) {
			els.kraskaQueueBtn.removeAttribute('disabled');
		}
		updateKraskaQueueButton();
		updateKraskaSelectAllCheckbox();
	}
}

function buildKraskaMetadataForItem(item, options = {}) {
	const baseTrail = Array.isArray(options.baseTrail) ? options.baseTrail : buildKraskaNormalizedTrail();
	const effectiveTrail = Array.isArray(options.trailOverride) ? options.trailOverride : baseTrail;
	const menuMetadata = {
		current_path: typeof state.kraska.currentPath === 'string' ? state.kraska.currentPath : null,
		title: typeof state.kraska.title === 'string' ? state.kraska.title : null,
	};

	if (Array.isArray(effectiveTrail) && effectiveTrail.length > 0) {
		menuMetadata.trail = effectiveTrail;
		menuMetadata.trail_labels = effectiveTrail
			.map((crumb) => (typeof crumb.label === 'string' ? crumb.label : null))
			.filter((label) => typeof label === 'string' && label.trim() !== '')
			.map((label) => label.trim());
	}

	if (options.branch && typeof options.branch === 'object') {
		const branchInfo = normalizeKraskaTrailSegment(options.branch);
		if (branchInfo) {
			menuMetadata.branch = branchInfo;
		}
	}

	const itemMeta = collectKraskaItemMetaHints(item?.meta);
	const itemMetadata = {
		label: typeof item?.label === 'string' ? item.label : null,
		summary: typeof item?.summary === 'string' ? item.summary : null,
	};
	if (itemMeta) {
		itemMetadata.meta = itemMeta;
	}

	const metadata = {
		source: 'kraska',
		menu: menuMetadata,
		item: itemMetadata,
	};

	const hints = deriveKraskaSeriesHints(menuMetadata, itemMetadata);
	if (Object.keys(hints).length > 0) {
		metadata.hints = hints;
	}

	const compacted = compactKraskaMetadata(metadata);
	return isNonEmptyObject(compacted) ? compacted : null;
}

function collectKraskaItemMetaHints(meta) {
	if (!meta || typeof meta !== 'object') {
		return null;
	}

	const result = {};
	const seasonValue = meta.season ?? meta.Season;
		if (seasonValue !== undefined) {
			const parsedSeason = Number.parseInt(seasonValue, 10);
			if (Number.isFinite(parsedSeason)) {
				result.season = parsedSeason;
			} else if (typeof seasonValue === 'string' && seasonValue.trim() !== '') {
				result.season_label = seasonValue.trim();
			}
	}

	const episodeValue = meta.episode ?? meta.Episode;
	if (episodeValue !== undefined) {
		const parsedEpisode = Number.parseInt(episodeValue, 10);
		if (Number.isFinite(parsedEpisode)) {
			result.episode = parsedEpisode;
			} else if (typeof episodeValue === 'string' && episodeValue.trim() !== '') {
			result.episode_label = episodeValue.trim();
		}
	}

	if (typeof meta.quality === 'string' && meta.quality.trim() !== '') {
		result.quality = meta.quality.trim();
	}

	if (typeof meta.year === 'number' && Number.isFinite(meta.year)) {
		result.year = meta.year;
	}

	const languages = Array.isArray(meta.languages) ? meta.languages : null;
	if (languages) {
		const normalizedLanguages = languages
			.map((code) => (typeof code === 'string' ? code.trim() : ''))
			.filter((code) => code !== '');
		if (normalizedLanguages.length > 0) {
			result.languages = normalizedLanguages;
		}
	}

	return Object.keys(result).length > 0 ? result : null;
}

function deriveKraskaSeriesHints(menuMetadata, itemMetadata) {
	const hints = {};
	const trailLabels = Array.isArray(menuMetadata?.trail_labels)
		? menuMetadata.trail_labels.filter((label) => typeof label === 'string' && label.trim() !== '').map((label) => label.trim())
		: [];

	let lastMeaningful = null;

	for (const label of trailLabels) {
		if (labelLooksLikeKraskaSeason(label)) {
			if (hints.season_label === undefined) {
				hints.season_label = label;
			}
			if (hints.season === undefined) {
				const seasonNumber = extractSeasonNumberFromLabel(label);
				if (seasonNumber !== null) {
					hints.season = seasonNumber;
				}
			}
			if (lastMeaningful) {
				hints.series_title = lastMeaningful;
			}
		} else if (!labelLooksGenericKraska(label)) {
			lastMeaningful = label;
		}
	}

	if (!hints.series_title && lastMeaningful) {
		hints.series_title = lastMeaningful;
	}

	const branchLabel = menuMetadata?.branch?.label;
	if (!hints.series_title && typeof branchLabel === 'string' && branchLabel.trim() !== '' && !labelLooksGenericKraska(branchLabel) && !labelLooksLikeKraskaSeason(branchLabel)) {
		hints.series_title = branchLabel.trim();
	}

	const menuTitle = menuMetadata?.title;
	if (!hints.series_title && typeof menuTitle === 'string' && menuTitle.trim() !== '' && !labelLooksGenericKraska(menuTitle) && !labelLooksLikeKraskaSeason(menuTitle)) {
		hints.series_title = menuTitle.trim();
	}

	const itemMeta = itemMetadata?.meta ?? {};
	if (itemMeta && typeof itemMeta === 'object') {
		if (itemMeta.season !== undefined && hints.season === undefined) {
			const metaSeason = Number.parseInt(itemMeta.season, 10);
			if (Number.isFinite(metaSeason)) {
				hints.season = metaSeason;
			}
		}

		if (itemMeta.episode !== undefined) {
			const metaEpisode = Number.parseInt(itemMeta.episode, 10);
			if (Number.isFinite(metaEpisode)) {
				hints.episode = metaEpisode;
			}
		}

		if (Array.isArray(itemMeta.languages)) {
			const normalizedLangs = normalizeLanguageTokenList(itemMeta.languages, 2);
			const suffix = normalizedLangs.length > 0 ? normalizedLangs.join(', ') : null;
			if (suffix) {
				hints.language_suffix = suffix;
			}
			if (normalizedLangs.length > 0) {
				hints.languages = normalizedLangs;
			}
		}
	}

	const itemLabel = typeof itemMetadata?.label === 'string' ? itemMetadata.label : null;
	if (itemLabel && itemLabel.trim() !== '') {
		const parsedLabel = parseKraskaEpisodeLabel(itemLabel);
		if (parsedLabel) {
			if (hints.season === undefined && Number.isFinite(parsedLabel.season)) {
				hints.season = parsedLabel.season;
			}
			if (hints.episode === undefined && Number.isFinite(parsedLabel.episode)) {
				hints.episode = parsedLabel.episode;
			}
			if (!hints.episode_title && parsedLabel.episodeTitle) {
				hints.episode_title = parsedLabel.episodeTitle;
			}
			if ((!hints.language_suffix || hints.language_suffix === '') && parsedLabel.languages.length > 0) {
				hints.language_suffix = parsedLabel.languages.join(', ');
			}
			if (!Array.isArray(hints.languages) || hints.languages.length === 0) {
				hints.languages = parsedLabel.languages;
			}
		}
	}

	Object.keys(hints).forEach((key) => {
		const value = hints[key];
		if (value === undefined || value === null || (typeof value === 'string' && value.trim() === '')) {
			delete hints[key];
		}
	});

	return hints;
}

function labelLooksLikeKraskaSeason(label) {
	if (typeof label !== 'string' || label.trim() === '') {
		return false;
	}
	const normalized = normalizeLabelForComparison(label);
	if (normalized === '') {
		return false;
	}
	if (/\bseason\b/.test(normalized) || /\bserie\b/.test(normalized) || /\bseria\b/.test(normalized) || /\bsezona\b/.test(normalized) || /\brada\b/.test(normalized)) {
		return true;
	}
	return /S\d{1,2}/i.test(label) || /\b\d{1,2}\.\s*serie\b/i.test(label);
}

function extractSeasonNumberFromLabel(label) {
	const match = typeof label === 'string' ? label.match(/(\d{1,2})/) : null;
	return match ? Number.parseInt(match[1], 10) : null;
}

function labelLooksGenericKraska(label) {
	const normalized = normalizeLabelForComparison(label);
	if (normalized === '') {
		return true;
	}
	const GENERIC = new Set([
		'browse',
		'home',
		'katalog',
		'kategorie',
		'categories',
		'filmy',
		'movies',
		'film',
		'documentaries',
		'dokumenty',
		'serialy',
		'serial',
		'seriale',
		'serials',
		'serialy cz',
		'serialy sk',
		'serialy en',
		'seriaty',
		'seriay',
		'seriay cz',
		'series',
		'stream cinema',
		'stream cinema online',
		'stream cinema cz',
		'stream cinema sk',
		'krask',
		'kra sk',
		'krask menu',
		'search',
		'vyhledavani',
		'vyhladavanie',
		'vyhledavanie',
		'vyhledat',
		'vyhledavac',
		'popular',
		'popularne',
		'popularni',
		'populární',
		'oblíbené',
		'oblibene',
		'favourites',
		'favorites',
	]);

	if (GENERIC.has(normalized)) {
		return true;
	}

	if (normalized.startsWith('category ') || normalized.startsWith('kategorie ')) {
		return true;
	}

	return false;
}

function normalizeLabelForComparison(label) {
	if (typeof label !== 'string') {
		return '';
	}
	let value = label.toLowerCase();
	if (typeof value.normalize === 'function') {
		value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
	}
	value = value.replace(/[^a-z0-9]+/g, ' ').trim();
	return value;
}

function buildLanguageSuffixFromList(languages) {
	const normalized = normalizeLanguageTokenList(languages, 2);
	return normalized.length > 0 ? normalized.join(', ') : null;
}

function normalizeLanguageTokenList(tokens, limit = 2) {
	if (!Array.isArray(tokens)) {
		return [];
	}
	const seen = new Set();
	const result = [];
	for (const raw of tokens) {
		if (result.length >= limit) break;
		if (typeof raw !== 'string') continue;
		let value = raw.trim();
		if (value === '') continue;
		value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		value = value.replace(/[^A-Za-z0-9+ ]/g, ' ');
		value = value.replace(/\s+/g, ' ').trim();
		if (value === '') continue;
		const base = value.includes('+') ? value.split('+')[0] : value;
		const upper = base.trim().toUpperCase();
		if (upper === '' || seen.has(upper)) continue;
		seen.add(upper);
		result.push(upper);
	}
	return result;
}

function normalizeLanguageTokensFromString(value, limit = 2) {
	if (typeof value !== 'string') {
		return [];
	}
	const tokens = value.split(/[,|]/).map((token) => token.trim()).filter((token) => token !== '');
	return normalizeLanguageTokenList(tokens, limit);
}

function parseKraskaEpisodeLabel(label) {
	const match = label.match(/^(?<season>\d{1,2})x(?<episode>\d{1,2})\s*-\s*(?<title>[^-]+?)(?:\s*-\s*(?<suffix>.+))?$/iu);
	if (!match || !match.groups) {
		return null;
	}
	const season = Number.parseInt(match.groups.season, 10);
	const episode = Number.parseInt(match.groups.episode, 10);
	const title = match.groups.title ? match.groups.title.trim() : '';
	const suffix = match.groups.suffix ? match.groups.suffix.trim() : '';
	const languages = suffix !== '' ? normalizeLanguageTokensFromString(suffix, 2) : [];
	return {
		season: Number.isFinite(season) ? season : undefined,
		episode: Number.isFinite(episode) ? episode : undefined,
		episodeTitle: title,
		languages,
	};
}

function buildKraskaNormalizedTrail(extraSegments = []) {
	const sourceTrail = Array.isArray(state.kraska.trail) ? state.kraska.trail : [];
	const normalized = [];
	for (const crumb of sourceTrail) {
		const normal = normalizeKraskaTrailSegment(crumb);
		if (normal) {
			normalized.push(normal);
		}
	}
	if (Array.isArray(extraSegments)) {
		for (const segment of extraSegments) {
			const normal = normalizeKraskaTrailSegment(segment);
			if (normal) {
				normalized.push(normal);
			}
		}
	}
	return normalized;
}

function appendKraskaTrail(trail, segment) {
	const base = Array.isArray(trail) ? trail.slice() : [];
	const normal = normalizeKraskaTrailSegment(segment);
	if (normal) {
		base.push(normal);
	}
	return base;
}

function normalizeKraskaTrailSegment(segment) {
	if (!segment || typeof segment !== 'object') {
		return null;
	}
	const label = typeof segment.label === 'string' ? segment.label.trim() : '';
	const path = typeof segment.path === 'string' ? segment.path.trim() : '';
	const payload = {};
	if (label !== '') {
		payload.label = label;
	}
	if (path !== '') {
		payload.path = path;
	}
	return Object.keys(payload).length > 0 ? payload : null;
}

function compactKraskaMetadata(value) {
	if (Array.isArray(value)) {
		const cleaned = value
			.map((entry) => compactKraskaMetadata(entry))
			.filter((entry) => entry !== null && entry !== undefined && !isPlainEmptyObject(entry));
		return cleaned;
	}

	if (value && typeof value === 'object') {
		const result = {};
		for (const [key, entry] of Object.entries(value)) {
			const cleaned = compactKraskaMetadata(entry);
			if (cleaned === null || cleaned === undefined) {
				continue;
			}
			if (isPlainEmptyObject(cleaned)) {
				continue;
			}
			result[key] = cleaned;
		}
		return result;
	}

	return value === undefined ? null : value;
}

function isPlainEmptyObject(value) {
	return typeof value === 'object' && value !== null && !Array.isArray(value) && Object.keys(value).length === 0;
}

function isNonEmptyObject(value) {
	if (typeof value !== 'object' || value === null) {
		return false;
	}
	if (Array.isArray(value)) {
		return value.length > 0;
	}
	return Object.keys(value).length > 0;
}

function normalizeKraskaItems(items) {
	return items.map((item, index) => {
		const type = typeof item?.type === 'string' ? item.type.toLowerCase() : 'unknown';
		const provider = typeof item?.provider === 'string' ? item.provider : 'kraska';
		const ident = typeof item?.ident === 'string' ? item.ident : null;
		const pathRaw = typeof item?.path === 'string' ? item.path : null;
		const path = pathRaw ? normalizeKraskaPath(pathRaw) : null;
		const cacheKey = ident ? `${provider}:ident:${ident}` : (path ? `${provider}:path:${path}` : `${provider}:${type}:${index}`);
		const queueModeRaw = typeof item?.queue_mode === 'string' ? item.queue_mode.toLowerCase() : null;
		const queueMode = queueModeRaw === 'branch' ? 'branch' : (queueModeRaw === 'single' || ident ? 'single' : null);
		const selectable = Boolean(item?.selectable ?? (queueMode !== null));
		return {
			...item,
			type,
			provider,
			ident,
			path,
			cacheKey,
			meta: typeof item?.meta === 'object' && item.meta !== null ? item.meta : {},
			art: typeof item?.art === 'object' && item.art !== null ? item.art : {},
			selectable,
			queueMode,
		};
	});
}

async function collectKraskaBranchItems(rootPath) {
	const startPath = normalizeKraskaPath(rootPath);
	const visited = new Set();
	const stack = [startPath];
	const collected = [];
	const MAX_BRANCH_VISITS = 2000;

	while (stack.length > 0) {
		const current = stack.pop();
		if (!current || visited.has(current)) {
			continue;
		}
		visited.add(current);

		let items = [];
		if (normalizeKraskaPath(state.kraska.currentPath ?? '/') === current) {
			items = state.kraska.items ?? [];
		} else {
			const params = new URLSearchParams({ path: current });
			const response = await fetchJson(`${API.kraskaMenu}?${params.toString()}`);
			const rawItems = Array.isArray(response?.data?.items) ? response.data.items : [];
			items = normalizeKraskaItems(rawItems);
		}

		for (const entry of items) {
			if (entry.queueMode === 'branch' && entry.path) {
				stack.push(entry.path);
			} else if (entry.ident) {
				collected.push(entry);
			}
		}

		if (visited.size > MAX_BRANCH_VISITS) {
			throw new Error('Exceeded traversal limit while expanding selection.');
		}
	}

	return collected;
}

async function openKraskaOptionsModal(item) {
	if (!state.user) {
		showToast('Please sign in first.', 'warning');
		return;
	}
	if (!els.modal) return;
	const preferredPath = typeof item?.path === 'string' && item.path !== '' ? normalizeKraskaPath(item.path) : null;
	const preferredIdent = typeof item?.ident === 'string' && item.ident !== '' ? item.ident : null;
	const externalId = preferredPath ?? preferredIdent;
	if (!externalId) {
		showToast('This entry does not expose downloadable variants.', 'warning');
		return;
	}

	state.kraska.selectedItem = {
		label: item?.label ?? item?.title ?? 'Untitled',
		ident: preferredIdent,
		path: preferredPath,
		provider: item?.provider ?? 'kraska',
		externalId,
		kra_ident: typeof item?.kra_ident === 'string' && item.kra_ident !== '' ? item.kra_ident : null,
	};
	if (item && typeof item === 'object' && item !== null) {
		['cacheKey', 'contextType', 'sourceItem', 'searchIndex', 'kra_ident'].forEach((key) => {
			if (key in item) {
				state.kraska.selectedItem[key] = item[key];
			}
		});
	}
	state.kraska.variants = [];
	state.kraska.variantsLoading = true;
	state.kraska.variantsError = null;
	state.kraska.variantQueueing = false;

	renderKraskaOptionsModal();
	els.modal.showModal();
	els.modal.addEventListener(
		'close',
		() => {
			state.kraska.selectedItem = null;
			state.kraska.variants = [];
			state.kraska.variantsError = null;
			state.kraska.variantsLoading = false;
			state.kraska.variantQueueing = false;
		},
		{ once: true }
	);

	try {
		const params = new URLSearchParams({ external_id: externalId });
		const response = await fetchJson(`${API.kraskaOptions}?${params.toString()}`);
		const variants = normalizeKraskaVariants(response?.data ?? []);
		state.kraska.variants = variants;
		state.kraska.variantsLoading = false;
		if (variants.length === 0) {
			state.kraska.variantsError = 'No download options available for this entry.';
		}
	} catch (error) {
		state.kraska.variantsLoading = false;
		state.kraska.variantsError = messageFromError(error);
	} finally {
		renderKraskaOptionsModal();
	}
}

function renderKraskaOptionsModal() {
	if (!els.modal) return;
	const context = state.kraska.selectedItem;
	if (!context) return;
	const title = context.label ?? 'Download options';
	const variants = state.kraska.variants ?? [];
	const loading = Boolean(state.kraska.variantsLoading);
	const error = state.kraska.variantsError ?? null;

	let bodyContent = '';
	if (loading) {
		bodyContent = '<div class="text-sm text-brand-300">Loading download options…</div>';
	} else if (error) {
		bodyContent = `<div class="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">${escapeHtml(error)}</div>`;
	} else if (variants.length === 0) {
		bodyContent = '<div class="text-sm text-slate-400">No download variants were returned for this item.</div>';
	} else {
		bodyContent = `
			<ul class="space-y-3">
				${variants.map((variant, index) => renderKraskaVariantOption(variant, index)).join('')}
			</ul>
		`;
	}

	els.modal.innerHTML = `
		<div>
			<header>
				<h3 class="text-lg font-semibold text-slate-100">Download options</h3>
				<p class="mt-1 text-sm text-slate-400">${escapeHtml(title)}</p>
			</header>
			<div class="modal-body space-y-4">
				${bodyContent}
			</div>
			<footer>
				<button type="button" data-close class="rounded-lg border border-slate-700/60 bg-slate-950/60 px-4 py-2 text-sm text-slate-200">Close</button>
			</footer>
		</div>
	`;

	const closeBtn = els.modal.querySelector('[data-close]');
	closeBtn?.addEventListener('click', () => {
		els.modal.close();
	});

	els.modal.querySelectorAll('[data-kraska-queue-variant]').forEach((button) => {
		if (!(button instanceof HTMLButtonElement)) return;
		button.addEventListener('click', () => {
			const index = Number.parseInt(button.dataset.kraskaQueueVariant ?? '', 10);
			if (Number.isFinite(index)) {
				queueKraskaVariant(index, button);
			}
		});
	});
}

function renderKraskaVariantOption(variant, index) {
	const chips = buildKraskaVariantChips(variant);
	const description = variant.description ?? null;
	const summary = description ? `<p class="text-xs text-slate-400">${escapeHtml(description)}</p>` : '';
	const queueLabel = state.kraska.variantQueueing ? 'Queuing…' : 'Queue this option';
	const disabledAttr = state.kraska.variantQueueing ? 'disabled' : '';

	return `
		<li class="rounded-2xl border border-slate-800/70 bg-slate-900/60 p-4">
			<div class="flex flex-col gap-3">
				<div class="flex flex-wrap items-center justify-between gap-3">
					<div>
						<h4 class="text-base font-semibold text-slate-100">${escapeHtml(variant.title ?? `Option ${index + 1}`)}</h4>
						${summary}
					</div>
					<button type="button" data-kraska-queue-variant="${index}" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white shadow transition hover:bg-brand-400 focus:outline-none focus:ring focus:ring-brand-500/40 disabled:cursor-not-allowed disabled:opacity-60" ${disabledAttr}>${escapeHtml(queueLabel)}</button>
				</div>
				${chips}
			</div>
		</li>
	`;
}

function buildKraskaVariantChips(variant) {
	const chips = [];
	if (variant.quality) chips.push(String(variant.quality));
	if (variant.size_human) chips.push(String(variant.size_human));
	if (variant.language) chips.push(String(variant.language));
	const durationSeconds = Number(variant.duration_seconds ?? 0);
	if (Number.isFinite(durationSeconds) && durationSeconds > 0) {
		chips.push(formatDuration(durationSeconds));
	}
	const bitrateLabel = formatBitrate(variant.bitrate_kbps);
	if (bitrateLabel) chips.push(bitrateLabel);
	if (variant.video_codec) chips.push(String(variant.video_codec));
	if (variant.audio_codec) chips.push(String(variant.audio_codec));
	if (variant.audio_channels) {
		const audioChannels = formatAudioChannels(variant.audio_channels);
		if (audioChannels) chips.push(audioChannels);
	}
	if (variant.fps) chips.push(`${variant.fps} fps`);

	if (chips.length === 0) {
		return '';
	}

	return `
		<div class="flex flex-wrap gap-2 text-xs text-slate-300">
			${chips
				.map((chip) => `<span class="rounded-full border border-slate-800/60 bg-slate-900/40 px-2 py-0.5">${escapeHtml(String(chip))}</span>`)
				.join('')}
		</div>
	`;
}

function normalizeKraskaVariants(variants) {
	return variants.map((variant, index) => {
		const idRaw = variant?.id ?? variant?.ident ?? variant?.external_id ?? index;
		const id = typeof idRaw === 'string' ? idRaw : String(idRaw);
		const title = typeof variant?.title === 'string' && variant.title !== ''
			? variant.title
			: `Option ${index + 1}`;
		const duration = Number(variant?.duration_seconds ?? variant?.duration ?? null);
		const bitrate = Number(variant?.bitrate_kbps ?? variant?.bitrate ?? null);
		const sizeBytes = Number(variant?.size_bytes ?? variant?.size ?? null);
		const audioChannels = Number(variant?.audio_channels ?? null);

		return {
			id,
			kra_ident: typeof variant?.kra_ident === 'string' && variant.kra_ident !== ''
				? variant.kra_ident
				: typeof variant?.source?.kra_ident === 'string' && variant.source.kra_ident !== ''
					? variant.source.kra_ident
					: null,
			title,
			quality: variant?.quality ?? null,
			language: variant?.language ?? null,
			size_bytes: Number.isFinite(sizeBytes) && sizeBytes > 0 ? sizeBytes : null,
			size_human: typeof variant?.size_human === 'string' ? variant.size_human : (Number.isFinite(sizeBytes) && sizeBytes > 0 ? formatRelativeSize(sizeBytes) : null),
			bitrate_kbps: Number.isFinite(bitrate) && bitrate > 0 ? bitrate : null,
			duration_seconds: Number.isFinite(duration) && duration > 0 ? duration : null,
			video_codec: variant?.video_codec ?? variant?.source?.video_codec ?? null,
			audio_codec: variant?.audio_codec ?? variant?.source?.audio_codec ?? null,
			audio_channels: Number.isFinite(audioChannels) && audioChannels > 0 ? audioChannels : null,
			fps: variant?.fps ?? null,
			description: typeof variant?.description === 'string' ? variant.description : null,
			source: variant?.source ?? null,
		};
	});
}

async function queueKraskaVariant(index, button) {
	if (state.kraska.variantQueueing) return;
	const variant = state.kraska.variants[index];
	const context = state.kraska.selectedItem;
	if (!variant || !context) return;
	const targetIdent = typeof variant.kra_ident === 'string' && variant.kra_ident !== ''
		? variant.kra_ident
		: typeof variant.id === 'string' && variant.id !== ''
			? variant.id
			: context.externalId;
	if (!targetIdent) {
		showToast('Missing ident for selected option.', 'error');
		return;
	}

	state.kraska.variantQueueing = true;
	if (button instanceof HTMLButtonElement) {
		button.setAttribute('disabled', 'true');
		button.textContent = 'Queuing…';
	}

	const titleParts = [context.label ?? 'Kra.sk item'];
	if (variant.title) {
		titleParts.push(variant.title);
	} else if (variant.quality) {
		titleParts.push(variant.quality);
	}
	const jobTitle = titleParts.filter(Boolean).join(' • ');

	try {
		await fetchJson(API.queue, {
			method: 'POST',
			body: JSON.stringify({
				items: [
					{
						provider: 'kraska',
						external_id: targetIdent,
						title: jobTitle,
					},
				],
			}),
		});
		showToast('Download queued.', 'success');
		if (context?.contextType === 'search' && context.cacheKey) {
			state.selectedSearch.delete(context.cacheKey);
			renderSearchResults();
		}
		els.modal?.close();
		state.kraska.selectedItem = null;
		state.kraska.variants = [];
		state.kraska.variantQueueing = false;
		loadJobs();
	} catch (error) {
		showToast(messageFromError(error), 'error');
		state.kraska.variantQueueing = false;
		if (button instanceof HTMLButtonElement) {
			button.removeAttribute('disabled');
			button.textContent = 'Queue this option';
		}
	}
}

function normalizeKraskaPath(value) {
	if (typeof value !== 'string') return '/';
	let path = value.trim();
	if (path === '') return '/';
	if (path.startsWith('http://') || path.startsWith('https://')) {
		try {
			const url = new URL(path);
			path = url.pathname + (url.search ?? '');
		} catch (error) {
			path = '/';
		}
	}
	if (!path.startsWith('/')) {
		path = '/' + path;
	}
	const parts = path.split('?');
	const cleanPath = parts[0].replace(/\/{2,}/g, '/');
	if (parts.length > 1 && parts[1] !== '') {
		return `${cleanPath}?${parts.slice(1).join('?')}`;
	}
	return cleanPath;
}

async function loadProviders() {
	if (!state.user) return;
	try {
		const response = await fetchJson(API.providers);
		const providers = Array.isArray(response?.data) ? response.data : [];
		state.providers = providers.map((provider) => ({
			...provider,
			name: provider.name ?? provider.key,
		}));
		renderProviders();
		renderProviderFilters();
	} catch (error) {
		state.providers = defaultProviders();
		renderProvidersError(error);
		renderProviderFilters();
	}
}

function renderProvidersError(error) {
	if (!els.providersError) return;
	const message = messageFromError(error);
	if (message.toLowerCase().includes('permission')) {
		toggleElement(els.providersError, false);
		toggleElement(els.providersEmpty, state.providers.length === 0);
		return;
	}

	els.providersError.innerHTML = `<p>${escapeHtml(message)}</p>`;
	toggleElement(els.providersError, true);
}

function renderProviderFilters() {
	if (!els.providerFilters) return;
	const providers = state.providers.length > 0 ? state.providers : defaultProviders();

	els.providerFilters.innerHTML = providers
		.map(
			(provider) => `
				<label class="inline-flex items-center gap-2 rounded-full border border-slate-800/70 bg-slate-900/60 px-3 py-1.5 text-sm text-slate-300">
					<input type="checkbox" value="${provider.key}" class="rounded border-slate-700 bg-slate-950 text-brand-500 focus:ring-brand-500/60" checked />
					${escapeHtml(provider.name ?? provider.key)}
				</label>
			`
		)
		.join('');
}

function getSelectedProviders() {
	if (!els.providerFilters) return [];
	const checkboxes = Array.from(els.providerFilters.querySelectorAll('input[type="checkbox"]'));
	const checked = checkboxes.filter((input) => input instanceof HTMLInputElement && input.checked);
	const providers = checked.map((input) => input.value).filter(Boolean);
	return providers.length > 0 ? providers : defaultProviders().map((p) => p.key);
}

function renderProviders() {
	if (!els.providersList) return;

	if (!state.isAdmin) {
		els.providersList.innerHTML = '';
		toggleElement(els.providersEmpty, true);
		return;
	}

	if (state.providers.length === 0) {
		toggleElement(els.providersEmpty, true);
		els.providersList.innerHTML = '';
		// Hide status wrapper when no providers
		if (els.providersStatusWrapper) toggleElement(els.providersStatusWrapper, false);
		return;
	}

	toggleElement(els.providersEmpty, false);
	els.providersList.innerHTML = state.providers
		.map((provider) => renderProviderCard(provider))
		.join('');
}

// Provider status aggregation (24h cached)
async function loadProviderStatuses(force = false) {
	if (!state.isAdmin) return;
	if (!els.providersStatusWrapper) return;
	toggleElement(els.providersStatusWrapper, true);
	toggleElement(els.providersStatusLoading, true);
	toggleElement(els.providersStatusError, false);
	els.providersStatusList.innerHTML = '';
	try {
		const url = force ? `${API.providersStatusAll}?refresh=1` : API.providersStatusAll;
		const response = await fetchJson(url);
		const items = Array.isArray(response?.data) ? response.data : [];
		renderProviderStatuses(items, response?.fetched_at, response?.cached === true);
	} catch (error) {
		if (els.providersStatusError) {
			els.providersStatusError.textContent = messageFromError(error);
			toggleElement(els.providersStatusError, true);
		}
	} finally {
		toggleElement(els.providersStatusLoading, false);
	}
}

function renderProviderStatuses(items, fetchedAt, cached) {
	if (!els.providersStatusList) return;
	if (!Array.isArray(items)) items = [];
	els.providersStatusList.innerHTML = items.map(renderProviderStatusItem).join('');
	if (els.providersStatusMeta) {
		const when = fetchedAt ? formatRelativeTime(fetchedAt) : 'n/a';
		els.providersStatusMeta.textContent = `Fetched ${when}${cached ? ' (cache)' : ''}`;
	}
}

function renderProviderStatusItem(status) {
	const provider = escapeHtml(status.provider || 'unknown');
	const ok = status.authenticated === true;
	const badge = ok
		? '<span class="rounded-full bg-emerald-500/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-300">OK</span>'
		: '<span class="rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-300">ERR</span>';
	const detailsParts = [];
	if (typeof status.days_left === 'number') detailsParts.push(`${status.days_left} days left`);
	if (typeof status.vip_days === 'number') detailsParts.push(`${status.vip_days} VIP days`);
	if (status.subscription_active === true) detailsParts.push('active');
	if (status.error) detailsParts.push(escapeHtml(String(status.error)));
	const details = detailsParts.length > 0 ? detailsParts.join(' · ') : '—';
	return `<li class="flex items-center justify-between rounded-lg border border-slate-800/60 bg-slate-900/50 px-3 py-2"><div class="flex items-center gap-2"><span class="text-xs font-semibold text-slate-200">${provider}</span>${badge}</div><div class="text-[11px] text-slate-400">${details}</div></li>`;
}

// Wire refresh button
document.getElementById('providers-status-refresh')?.addEventListener('click', () => loadProviderStatuses(true));

function renderProviderCard(provider) {
	const configEntries = Object.entries(provider.config ?? {})
		.map(([key, value]) => `<div class="flex justify-between text-sm text-slate-300"><span class="font-medium text-slate-200">${escapeHtml(key)}</span><span>${escapeHtml(String(value ?? ''))}</span></div>`)
		.join('');

	return `
		<li data-provider-id="${provider.id}" class="rounded-2xl border border-slate-800/70 bg-slate-900/50 p-5">
			<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
				<div>
					<h4 class="text-lg font-semibold text-slate-100">${escapeHtml(provider.name ?? provider.key)}</h4>
					<div class="text-sm text-slate-400">Key: ${escapeHtml(provider.key)}</div>
					<div class="mt-2 flex items-center gap-2 text-xs uppercase tracking-wide ${provider.enabled ? 'text-emerald-300' : 'text-rose-300'}">
						<span class="inline-flex h-2 w-2 rounded-full ${provider.enabled ? 'bg-emerald-400' : 'bg-rose-400'}"></span>
						${provider.enabled ? 'Enabled' : 'Disabled'}
					</div>
				</div>
				<div class="flex flex-wrap gap-2">
					<button type="button" data-provider-test class="rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:border-brand-400/60 hover:text-brand-200">Test</button>
					<button type="button" data-provider-edit class="rounded-lg border border-brand-500/40 bg-brand-500/20 px-3 py-1.5 text-xs font-semibold text-brand-100 transition hover:bg-brand-500/30">Edit</button>
					<button type="button" data-provider-delete class="rounded-lg border border-rose-500/40 bg-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-100 transition hover:bg-rose-500/30">Delete</button>
				</div>
			</div>
			<dl class="mt-4 grid gap-2 text-xs text-slate-400">
				<div class="flex justify-between"><span>Updated</span><span>${formatRelativeTime(provider.updated_at)}</span></div>
				<div class="flex justify-between"><span>Created</span><span>${formatRelativeTime(provider.created_at)}</span></div>
			</dl>
			${configEntries ? `<div class="mt-4 space-y-2 border-t border-slate-800/60 pt-4">${configEntries}</div>` : ''}
		</li>
	`;
}

async function loadUsers() {
	if (!state.user || !state.isAdmin) {
		return;
	}

	state.usersLoading = true;
	toggleElement(els.usersError, false);
	renderUsers();

	try {
		const response = await fetchJson(API.users);
		state.users = Array.isArray(response?.data) ? response.data : [];
	} catch (error) {
		console.warn('Failed to load users', error);
		if (error.status === 401 || error.status === 403) {
			toggleElement(els.usersError, false);
		} else if (els.usersError) {
			els.usersError.textContent = messageFromError(error);
			toggleElement(els.usersError, true);
		}
	} finally {
		state.usersLoading = false;
		renderUsers();
	}
}

function renderUsers() {
	if (!els.usersTable) return;

	if (!state.isAdmin) {
		els.usersTable.innerHTML = '';
		toggleElement(els.usersEmpty, false);
		return;
	}

	const emptyMessage = state.usersLoading ? 'Loading users…' : 'No users found yet.';
	if (els.usersEmpty) {
		els.usersEmpty.textContent = emptyMessage;
	}

	if (state.usersLoading) {
		els.usersTable.innerHTML = '';
		toggleElement(els.usersEmpty, true);
		return;
	}

	if (state.users.length === 0) {
		els.usersTable.innerHTML = '';
		toggleElement(els.usersEmpty, true);
		return;
	}

	toggleElement(els.usersEmpty, false);
	els.usersTable.innerHTML = state.users
		.map((user) => renderUserRow(user))
		.join('');
}

function renderUserRow(user) {
	const role = String(user.role ?? 'user');
	const isAdmin = role === 'admin';
	const isSelf = state.user && Number(state.user.id) === Number(user.id);
	const roleClasses = isAdmin
		? 'border-brand-500/40 bg-brand-500/15 text-brand-100'
		: 'border-slate-700/60 bg-slate-900/50 text-slate-300';
	const deleteDisabled = isSelf ? 'disabled' : '';
	const deleteClasses = isSelf
		? 'rounded-lg border border-slate-700/60 bg-slate-900/40 px-3 py-1.5 text-xs font-semibold text-slate-500 cursor-not-allowed'
		: 'rounded-lg border border-rose-500/40 bg-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-100 transition hover:bg-rose-500/30';

	return `
		<tr data-user-id="${user.id}" class="divide-x divide-slate-800/70">
			<td class="px-4 py-3 font-medium text-slate-100">${escapeHtml(user.name ?? user.email ?? '')}</td>
			<td class="px-4 py-3 text-slate-300">${escapeHtml(user.email ?? '')}</td>
			<td class="px-4 py-3">
				<span class="inline-flex items-center gap-2 rounded-full border ${roleClasses} px-3 py-1 text-xs uppercase tracking-wide">${escapeHtml(role)}</span>
			</td>
			<td class="px-4 py-3 text-slate-400">${formatRelativeTime(user.created_at)}</td>
			<td class="px-4 py-3">
				<div class="flex justify-end gap-2">
					<button type="button" data-user-edit class="rounded-lg border border-brand-500/40 bg-brand-500/20 px-3 py-1.5 text-xs font-semibold text-brand-100 transition hover:bg-brand-500/30">Edit</button>
					<button type="button" data-user-delete ${deleteDisabled} class="${deleteClasses}">${isSelf ? 'Signed in' : 'Delete'}</button>
				</div>
			</td>
		</tr>
	`;
}

async function loadAudit(refresh = false) {
	if (!state.user || !state.isAdmin) return;
	if (state.auditLoading) return;
	if (!refresh && (state.auditCursor === null || state.auditCursor === undefined)) {
		return;
	}

	if (refresh) {
		state.auditLogs = [];
		state.auditCursor = null;
		renderAudit();
	}

	state.auditLoading = true;
	toggleElement(els.auditLoading, true);
	toggleElement(els.auditError, false);

	const params = new URLSearchParams({ limit: '50' });
	if (!refresh && state.auditCursor) {
		params.set('before', String(state.auditCursor));
	}

	try {
		const response = await fetchJson(`${API.audit}?${params.toString()}`);
		const entries = Array.isArray(response?.data) ? response.data : [];
		if (refresh) {
			state.auditLogs = entries;
		} else {
			state.auditLogs = state.auditLogs.concat(entries);
		}
		state.auditCursor = response?.next_cursor ? Number(response.next_cursor) : null;
	} catch (error) {
		if (els.auditError) {
			els.auditError.textContent = messageFromError(error);
			toggleElement(els.auditError, true);
		}
	} finally {
		state.auditLoading = false;
		toggleElement(els.auditLoading, false);
		renderAudit();
	}
}

function renderAudit() {
	if (!els.auditTable || !els.auditEmpty) return;

	if (state.auditLogs.length === 0) {
		els.auditTable.innerHTML = '';
		if (!state.auditLoading) {
			toggleElement(els.auditEmpty, true);
		}
	} else {
		toggleElement(els.auditEmpty, false);
		els.auditTable.innerHTML = state.auditLogs
			.map((entry) => renderAuditRow(entry))
			.join('');
	}

	const hasMore = Boolean(state.auditCursor);
	toggleElement(els.auditLoadMore, hasMore);
	if (els.auditLoadMore) {
		els.auditLoadMore.disabled = !hasMore || state.auditLoading;
	}
}

function renderAuditRow(entry) {
	const createdAt = typeof entry.created_at === 'string' ? entry.created_at : '';
	const relative = formatRelativeTime(createdAt);
	const absoluteDate = parseIsoDate(createdAt);
	const absolute = absoluteDate ? absoluteDate.toLocaleString() : 'Unknown time';
	const user = entry.user ?? {};
	const userName = user.name || user.email || `User #${user.id ?? ''}`;
	const subject = entry.subject ?? {};
	const subjectLabel = subject.type ? `${subject.type}${subject.id ? ` #${subject.id}` : ''}` : '—';
	const payload = entry.payload && typeof entry.payload === 'object' ? entry.payload : {};
	const payloadKeys = Object.keys(payload);
	const payloadDisplay = payloadKeys.length > 0
		? `<code class="whitespace-pre-wrap break-words text-xs text-slate-200">${escapeHtml(JSON.stringify(payload))}</code>`
		: '<span class="text-xs text-slate-500">—</span>';

	return `
		<tr class="divide-x divide-slate-800/70">
			<td class="px-4 py-3 text-xs text-slate-300"><span title="${escapeHtml(absolute)}">${escapeHtml(relative)}</span></td>
			<td class="px-4 py-3 text-sm text-slate-200">
				<div class="flex flex-col">
					<span class="font-medium">${escapeHtml(userName)}</span>
					${user.email && user.email !== userName ? `<span class="text-xs text-slate-400">${escapeHtml(user.email)}</span>` : ''}
				</div>
			</td>
			<td class="px-4 py-3 text-sm text-slate-100">${escapeHtml(entry.action ?? '')}</td>
			<td class="px-4 py-3 text-sm text-slate-200">${escapeHtml(subjectLabel)}</td>
			<td class="px-4 py-3">${payloadDisplay}</td>
		</tr>
	`;
}

function openUserModal(user) {
	if (!els.modal) return;
	const isEdit = Boolean(user);
	const title = isEdit ? `Edit ${escapeHtml(user.name ?? user.email)}` : 'Invite a user';
	const formId = `user-form-${Date.now()}`;
	const role = user?.role ?? 'user';
	const passwordLabel = isEdit ? 'New password (optional)' : 'Password';
	const passwordHelper = isEdit ? 'Leave blank to keep the existing password.' : 'Minimum 8 characters.';
	const passwordRequired = isEdit ? '' : 'required';

	els.modal.innerHTML = `
		<form id="${formId}" method="dialog" class="space-y-4">
			<header>
				<h3 class="text-xl font-semibold">${title}</h3>
				<p class="mt-1 text-sm text-slate-400">${isEdit ? 'Update user details and roles.' : 'Create a new account that can sign in to this dashboard.'}</p>
			</header>
			<div class="modal-body space-y-4">
				<div class="form-grid two-cols">
					<label class="flex flex-col gap-1 text-sm">
						<span class="font-medium text-slate-200">Name</span>
						<input name="name" value="${escapeHtml(user?.name ?? '')}" placeholder="Jane Doe" required class="rounded-lg border border-slate-700 bg-slate-950/60 px-3 py-2 text-slate-100" />
					</label>
					<label class="flex flex-col gap-1 text-sm">
						<span class="font-medium text-slate-200">Email</span>
						<input name="email" type="email" value="${escapeHtml(user?.email ?? '')}" placeholder="jane@example.com" required class="rounded-lg border border-slate-700 bg-slate-950/60 px-3 py-2 text-slate-100" />
					</label>
				</div>
				<label class="flex flex-col gap-1 text-sm">
					<span class="font-medium text-slate-200">Role</span>
					<select name="role" class="rounded-lg border border-slate-700 bg-slate-950/60 px-3 py-2 text-slate-100">
						<option value="user" ${role === 'user' ? 'selected' : ''}>Standard user</option>
						<option value="admin" ${role === 'admin' ? 'selected' : ''}>Administrator</option>
					</select>
					<p class="text-xs text-slate-400">Administrators can manage providers, users, and advanced settings.</p>
				</label>
				<label class="flex flex-col gap-1 text-sm">
					<span class="font-medium text-slate-200">${passwordLabel}</span>
					<input name="password" type="password" minlength="8" ${passwordRequired} class="rounded-lg border border-slate-700 bg-slate-950/60 px-3 py-2 text-slate-100" />
					<p class="text-xs text-slate-400">${passwordHelper}</p>
				</label>
			</div>
			<footer>
				<button type="button" data-close class="rounded-lg border border-slate-700/60 bg-slate-950/60 px-4 py-2 text-sm text-slate-200">Cancel</button>
				<button type="submit" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white">${isEdit ? 'Save changes' : 'Create user'}</button>
			</footer>
		</form>
	`;

	const form = els.modal.querySelector(`#${formId}`);
	const closeBtn = els.modal.querySelector('[data-close]');
	const submitBtn = form?.querySelector('button[type="submit"]');

	closeBtn?.addEventListener('click', () => {
		els.modal.close();
	});

	form?.addEventListener('submit', async (event) => {
		event.preventDefault();
		if (!form) return;

		const formData = new FormData(form);
		const payload = {
			name: String(formData.get('name') ?? '').trim(),
			email: String(formData.get('email') ?? '').trim(),
			role: String(formData.get('role') ?? 'user').trim().toLowerCase(),
		};
		const password = String(formData.get('password') ?? '');

		if (!payload.name || !payload.email) {
			showToast('Name and email are required.', 'error');
			return;
		}

		if (!isEdit || password !== '') {
			if (password.length < 8) {
				showToast('Password must be at least 8 characters long.', 'error');
				return;
			}
			payload.password = password;
		}

		submitBtn?.setAttribute('disabled', 'true');

		try {
			if (isEdit && user) {
				await fetchJson(`${API.users}?id=${user.id}`, {
					method: 'PATCH',
					body: JSON.stringify(payload),
				});
				showToast('User updated.', 'success');
			} else {
				await fetchJson(API.users, {
					method: 'POST',
					body: JSON.stringify(payload),
				});
				showToast('User created.', 'success');
			}

			els.modal.close();
			await loadUsers();
		} catch (error) {
			showToast(messageFromError(error), 'error');
			submitBtn?.removeAttribute('disabled');
		}
	});

	els.modal.showModal();
	form?.querySelector('input[name="name"]')?.focus();
}

async function confirmUserDelete(userId) {
	const user = state.users.find((item) => Number(item.id) === userId);
	const label = user?.name ?? user?.email ?? 'this user';
	if (state.user && Number(state.user.id) === userId) {
		showToast('You cannot delete the account that is currently signed in.', 'warning');
		return;
	}
	if (!window.confirm(`Delete ${label}? This cannot be undone.`)) {
		return;
	}

	try {
		await fetchJson(`${API.users}?id=${userId}`, { method: 'DELETE' });
		showToast('User deleted.', 'success');
		await loadUsers();
	} catch (error) {
		showToast(messageFromError(error), 'error');
	}
}

function renderSearchResults() {
	els.searchResults.innerHTML = '';

	if (state.searchResults.length === 0) {
		toggleElement(els.searchEmpty, true);
		toggleElement(els.queueSelectionBtn, false);
		return;
	}

	toggleElement(els.searchEmpty, false);
	const items = state.searchResults
		.map((item, index) => {
			const providerKey = String(item.provider ?? '').toLowerCase();
			const isKraska = providerKey === 'kraska';
			const optionsMarkup = isKraska
				? `<div class="pt-2"><button type="button" data-search-kraska-options="${index}" class="inline-flex items-center gap-1 rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white shadow transition hover:bg-brand-400 focus:outline-none focus:ring focus:ring-brand-500/40">Choose stream</button></div>`
				: '';
			const checked = state.selectedSearch.has(item.cacheKey) ? 'checked' : '';
			const resolution = item.resolution ?? (item.video_width && item.video_height ? `${item.video_width}x${item.video_height}` : null);
			const fpsLabel = formatFps(item.video_fps);
			const bitrateLabel = formatBitrate(item.bitrate_kbps);
			const videoCodec = item.video_codec ? String(item.video_codec).toUpperCase() : null;
			const videoChips = [resolution, videoCodec, fpsLabel, bitrateLabel].filter(Boolean);
			const audioCodec = item.audio_codec ? String(item.audio_codec).toUpperCase() : null;
			const audioChannels = formatAudioChannels(item.audio_channels);
			const audioLanguage = item.audio_language ? String(item.audio_language).toUpperCase() : null;
			const audioParts = [audioCodec, audioChannels, audioLanguage].filter(Boolean);
			const videoMarkup =
				videoChips.length > 0
					? `<div class="flex flex-wrap gap-2 text-xs text-slate-300">${videoChips
							.map((chip) => `<span class="rounded-full border border-slate-800/60 bg-slate-900/40 px-2 py-0.5">${escapeHtml(chip)}</span>`)
							.join('')}</div>`
					: '';
			const audioMarkup =
				audioParts.length > 0
					? `<div class="flex flex-wrap items-center gap-2 text-xs text-slate-400"><span class="font-medium text-slate-300">Audio</span><span>${audioParts
							.map((part) => escapeHtml(part))
							.join(' · ')}</span></div>`
					: '';
			return `
				<li class="group rounded-2xl border border-slate-800/70 bg-slate-900/60 p-4 transition hover:border-brand-500/40">
					<div class="flex items-start gap-4">
						<input type="checkbox" data-result-key="${item.cacheKey}" class="mt-1 h-5 w-5 rounded border-slate-700 bg-slate-950 text-brand-500 focus:ring-brand-500/60" ${checked} />
						<div class="flex-1 space-y-2">
							<div class="flex flex-wrap items-center gap-2">
								<h4 class="text-lg font-semibold text-slate-100">${escapeHtml(item.title)}</h4>
								<span class="rounded-full border border-slate-800/70 bg-slate-950/60 px-2 py-0.5 text-xs uppercase tracking-wide text-slate-300">${escapeHtml(item.provider)}</span>
								<span class="text-xs text-slate-400">${formatRelativeSize(item.size_bytes)}</span>
								${item.duration_seconds ? `<span class="text-xs text-slate-400">${formatDuration(item.duration_seconds)}</span>` : ''}
							</div>
							<div class="text-xs text-slate-500">External ID: ${escapeHtml(item.external_id)}</div>
							${videoMarkup}
							${audioMarkup}
							${optionsMarkup}
						</div>
						${item.thumbnail ? `<img src="${escapeHtml(item.thumbnail)}" alt="${escapeHtml(item.title)}" class="h-16 w-16 rounded-lg object-cover" loading="lazy" />` : ''}
					</div>
				</li>
			`;
		})
		.join('');

	els.searchResults.innerHTML = items;
	els.searchResults.querySelectorAll('input[type="checkbox"]').forEach((input) => {
		input.addEventListener('change', handleResultSelectionChange);
	});
	els.searchResults.querySelectorAll('[data-search-kraska-options]').forEach((button) => {
		if (!(button instanceof HTMLButtonElement)) return;
		button.addEventListener('click', () => {
			const index = Number.parseInt(button.dataset.searchKraskaOptions ?? '', 10);
			if (Number.isNaN(index)) return;
			const entry = state.searchResults[index];
			if (!entry) return;
			const normalizedPath = typeof entry.path === 'string' && entry.path !== '' ? entry.path : null;
			const inferredPath = typeof entry.kraska_path === 'string' && entry.kraska_path !== '' ? entry.kraska_path : normalizedPath;
			const preferredPath = typeof inferredPath === 'string' && inferredPath !== '' ? normalizeKraskaPath(inferredPath) : null;
			const fallbackIdent = typeof entry.kra_ident === 'string' && entry.kra_ident !== '' ? entry.kra_ident : null;
			openKraskaOptionsModal({
				label: entry.title,
				ident: fallbackIdent ?? (typeof entry.external_id === 'string' && !entry.external_id.startsWith('/') ? entry.external_id : null),
				path: preferredPath ?? (typeof entry.external_id === 'string' && entry.external_id.startsWith('/') ? entry.external_id : null),
				provider: entry.provider,
				cacheKey: entry.cacheKey,
				contextType: 'search',
				sourceItem: entry,
				searchIndex: index,
				kra_ident: fallbackIdent ?? null,
			});
		});
	});
	updateQueueButton();
}

function renderSearchWarnings(duplicates) {
	const el = els.searchWarnings;
	if (!(el instanceof HTMLElement)) {
		return;
	}
	const items = Array.isArray(duplicates)
		? Array.from(new Set(duplicates.filter((value) => typeof value === 'string' && value.trim() !== '').map((value) => value.trim())))
		: [];
	if (items.length === 0) {
		el.textContent = '';
		toggleElement(el, false);
		return;
	}
	el.innerHTML = `<span class="font-semibold">Already downloaded:</span> ${escapeHtml(items.join(', '))}`;
	toggleElement(el, true);
}

function renderSearchMeta(query, count, providers) {
	if (!els.searchMeta) return;
	const providerLabel = providers.length > 0 ? providers.join(', ') : 'all providers';
	els.searchMeta.textContent = `${count} result${count === 1 ? '' : 's'} for "${query}" via ${providerLabel}.`;
}

function renderSearchErrors(errors) {
	if (!els.searchErrors) return;
	if (!errors || errors.length === 0) {
		toggleElement(els.searchErrors, false);
		return;
	}

	toggleElement(els.searchErrors, true);
	els.searchErrors.innerHTML = errors
		.map(
			(error) => `
				<div class="rounded-lg border border-rose-500/40 bg-rose-500/10 p-3 text-sm text-rose-200">
					<strong>${escapeHtml(error.provider ?? 'Provider')}</strong>: ${escapeHtml(error.message ?? 'Unknown error')}
				</div>
			`
		)
		.join('');
}

function handleResultSelectionChange(event) {
	const input = event.target;
	if (!(input instanceof HTMLInputElement)) return;
	const key = input.dataset.resultKey;
	if (!key) return;

	if (input.checked) {
		state.selectedSearch.add(key);
	} else {
		state.selectedSearch.delete(key);
	}

	updateQueueButton();
}

function updateQueueButton() {
	const hasSelection = state.selectedSearch.size > 0;
	toggleElement(els.queueSelectionBtn, hasSelection, 'inline-flex');
	if (els.queueSelectionBtn) {
		els.queueSelectionBtn.textContent = `Add ${state.selectedSearch.size} item${state.selectedSearch.size === 1 ? '' : 's'} to queue`;
	}
}

async function queueSelectedResults() {
	if (state.isQueueSubmitting || state.selectedSearch.size === 0) return;
	state.isQueueSubmitting = true;
	els.queueSelectionBtn?.setAttribute('disabled', 'true');

	const itemsPayload = Array.from(state.selectedSearch)
		.map((cacheKey) => state.searchResults.find((item) => item.cacheKey === cacheKey))
		.filter(Boolean)
		.map((item) => ({
			provider: item.provider,
			external_id: item.external_id,
			title: item.title,
			size_bytes: item.size_bytes,
		}));

	if (itemsPayload.length === 0) {
		showToast('Nothing to queue.', 'warning');
		resetQueueButton();
		return;
	}

	try {
		await fetchJson(API.queue, {
			method: 'POST',
			body: JSON.stringify({ items: itemsPayload }),
		});

		showToast(`Queued ${itemsPayload.length} item${itemsPayload.length === 1 ? '' : 's'}.`, 'success');
		state.selectedSearch.clear();
		renderSearchResults();
		loadJobs();
	} catch (error) {
		showToast(messageFromError(error), 'error');
	} finally {
		resetQueueButton();
	}
}

function resetQueueButton() {
	state.isQueueSubmitting = false;
	els.queueSelectionBtn?.removeAttribute('disabled');
	updateQueueButton();
}

function normalizeJob(job) {
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
	const priorityDiff = Number(a?.priority ?? 0) - Number(b?.priority ?? 0);
	if (priorityDiff !== 0) {
		return priorityDiff;
	}

	const positionDiff = Number(a?.position ?? 0) - Number(b?.position ?? 0);
	if (positionDiff !== 0) {
		return positionDiff;
	}

	const createdA = parseIsoDate(a?.created_at ?? 0)?.getTime();
	const createdB = parseIsoDate(b?.created_at ?? 0)?.getTime();
	if (Number.isFinite(createdA) && Number.isFinite(createdB) && createdA !== createdB) {
		return createdA - createdB;
	}

	return Number(a?.id ?? 0) - Number(b?.id ?? 0);
}

function sortJobsInPlace(jobs) {
	if (!Array.isArray(jobs)) return;
	jobs.sort(compareJobs);
}

function mergeJobIntoState(job) {
	const normalized = normalizeJob(job);
	if (!normalized || normalized.id === undefined || normalized.id === null) {
		return false;
	}

	const jobId = Number(normalized.id);
	if (!Number.isFinite(jobId)) {
		return false;
	}

	const index = state.jobs.findIndex((item) => Number(item.id) === jobId);
	if (index === -1) {
		state.jobs.push(normalized);
	} else {
		state.jobs[index] = {
			...state.jobs[index],
			...normalized,
		};
	}

	sortJobsInPlace(state.jobs);

	return true;
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

async function openProviderModal(provider) {
	if (!els.modal) return;

	const isEdit = Boolean(provider);
	const availableTemplates = Object.keys(providerCatalog);
	const initialTemplate = (() => {
		if (provider && providerCatalog[provider.key]) return provider.key;
		if (availableTemplates.length > 0) return availableTemplates[0];
		return '';
	})();
	const initialKey = provider?.key ?? providerCatalog[initialTemplate]?.key ?? '';
	const title = isEdit ? `Edit ${provider?.name ?? provider?.key ?? 'provider'}` : 'Add provider';
	const formId = `provider-form-${Date.now()}`;

	const templateSelectMarkup = isEdit
		? ''
		: `
		<label class="flex flex-col gap-1 text-sm">
			<span class="font-medium text-slate-200">Provider type</span>
			<select name="template" class="rounded-lg border border-slate-700 bg-slate-950/60 px-3 py-2 text-slate-100">
				${availableTemplates
					.map((key) => `<option value="${key}" ${key === initialTemplate ? 'selected' : ''}>${escapeHtml(providerCatalog[key].label)}</option>`)
					.join('')}
			</select>
			<p class="text-xs text-slate-400">Choose a provider integration.</p>
		</label>`;

	els.modal.innerHTML = `
		<form id="${formId}" method="dialog" class="space-y-4">
			<header>
				<h3 class="text-xl font-semibold">${escapeHtml(title)}</h3>
				<p class="mt-1 text-sm text-slate-400">Configure credentials for your download provider.</p>
			</header>
			<div class="modal-body space-y-4">
				${templateSelectMarkup}
				<div class="form-grid two-cols">
					<label class="flex flex-col gap-1 text-sm">
						<span class="font-medium text-slate-200">Key</span>
						<input name="key" value="${escapeHtml(initialKey)}" class="rounded-lg border border-slate-700 bg-slate-950/60 px-3 py-2 text-slate-100" required />
					</label>
					<label class="flex flex-col gap-1 text-sm">
						<span class="font-medium text-slate-200">Display name</span>
						<input name="name" value="${escapeHtml(provider?.name ?? providerCatalog[initialTemplate]?.defaultName ?? providerCatalog[initialTemplate]?.label ?? '')}" placeholder="${escapeHtml(providerCatalog[initialTemplate]?.defaultName ?? '')}" class="rounded-lg border border-slate-700 bg-slate-950/60 px-3 py-2 text-slate-100" required />
					</label>
				</div>
				<div class="space-y-2">
					<label class="inline-flex items-center gap-2 text-sm text-slate-300">
						<input type="checkbox" name="enabled" ${provider?.enabled !== false ? 'checked' : ''} class="rounded border-slate-700 bg-slate-950 text-brand-500 focus:ring-brand-500/60" />
						Provider enabled
					</label>
					<label class="inline-flex items-center gap-2 text-sm text-slate-300">
						<input type="checkbox" name="debug" ${provider?.config?.debug === true ? 'checked' : ''} class="rounded border-slate-700 bg-slate-950 text-brand-500 focus:ring-brand-500/60" />
						<span>Debug mode <span class="text-xs text-slate-500">(logs detailed provider operations to storage/logs/kraska_debug.log)</span></span>
					</label>
				</div>
				<div data-provider-config class="space-y-3"></div>
			</div>
			<footer>
				<button type="button" data-close class="rounded-lg border border-slate-700/60 bg-slate-950/60 px-4 py-2 text-sm text-slate-200">Cancel</button>
				<button type="submit" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white">${isEdit ? 'Save changes' : 'Create provider'}</button>
			</footer>
		</form>
	`;

	const form = els.modal.querySelector(`#${formId}`);
	const closeBtn = els.modal.querySelector('[data-close]');
	const templateSelect = form?.querySelector('select[name="template"]') ?? null;
	const keyInput = form?.querySelector('input[name="key"]');
	const nameInput = form?.querySelector('input[name="name"]');
	const fieldsContainer = form?.querySelector('[data-provider-config]');

	const tempConfig = {};
	let activeTemplate = isEdit
		? (providerCatalog[initialKey] ? initialKey : initialTemplate)
		: (templateSelect ? templateSelect.value : initialTemplate);

	if (provider?.config && providerCatalog[provider.key]) {
		tempConfig[provider.key] = { ...provider.config };
	}

	const captureCurrentConfig = () => {
		if (!fieldsContainer) return;
		if (activeTemplate === '__custom' || !providerCatalog[activeTemplate]) {
			const raw = fieldsContainer.querySelector('textarea[name="config.__raw"]');
			tempConfig.__customRaw = raw ? raw.value : tempConfig.__customRaw ?? '';
			return;
		}
		const def = providerCatalog[activeTemplate];
		const previous = tempConfig[activeTemplate]
			?? (provider?.key === activeTemplate ? provider?.config ?? {} : {});
		const entry = { ...previous };
		def.fields.forEach((field) => {
			const input = fieldsContainer.querySelector(`[name="config.${field.name}"]`);
			let value = input ? input.value : '';
			if (field.type === 'password') {
				const existing = typeof previous[field.name] === 'string' ? previous[field.name] : '';
				if (value === '' && existing !== '') {
					value = existing;
				}
			} else {
				value = value.trim();
			}
			entry[field.name] = value;
		});
		tempConfig[activeTemplate] = entry;
	};

	const renderConfigFields = () => {
		if (!fieldsContainer) return;
		const definition = providerCatalog[activeTemplate];
		if (!definition) return;

		const values = tempConfig[activeTemplate]
			?? (provider?.key === activeTemplate ? provider?.config ?? {} : {});

		const description = definition.description
			? `<p class="text-sm text-slate-400">${escapeHtml(definition.description)}</p>`
			: '';

		const tokenHelp = activeTemplate === 'webshare'
			? '<p class="text-xs text-slate-500">A new Webshare token is requested automatically when you save this provider.</p>'
			: '';

		const fieldsHtml = definition.fields
			.map((field) => {
				const storedValue = values[field.name];
				const hasStoredValue = !(
					storedValue === undefined ||
					storedValue === null ||
					(typeof storedValue === 'string' && storedValue === '')
				);
				const displayValue = field.type === 'password'
					? ''
					: hasStoredValue
						? String(storedValue)
						: '';
				const helpLines = [];
				if (field.help) {
					helpLines.push(field.help);
				}
				if (field.type === 'password' && hasStoredValue) {
					helpLines.push('Leave blank to keep the current password.');
				}
				const helpText = helpLines
					.map((line) => `<p class="text-xs text-slate-500">${escapeHtml(line)}</p>`)
					.join('');
				const inputType = field.type === 'password'
					? 'password'
					: field.type === 'number'
						? 'number'
						: 'text';
				const autocompleteAttr = inputType === 'password'
					? 'autocomplete="new-password"'
					: inputType === 'text'
						? 'autocomplete="off"'
						: '';
				const extraAttributes = [];
				if (field.placeholder) {
					extraAttributes.push(`placeholder="${escapeHtml(field.placeholder)}"`);
				}
				if (field.required) {
					extraAttributes.push('required');
				}
				if (autocompleteAttr) {
					extraAttributes.push(autocompleteAttr);
				}
				if (field.attributes && typeof field.attributes === 'object') {
					Object.entries(field.attributes).forEach(([attr, val]) => {
						if (val === false || val === null || val === undefined) {
							return;
						}
						if (val === true) {
							extraAttributes.push(attr);
							return;
						}
						extraAttributes.push(`${attr}="${escapeHtml(String(val))}"`);
					});
				}
				const attributeList = [
					`name="config.${field.name}"`,
					`type="${inputType}"`,
					`value="${escapeHtml(displayValue)}"`,
					...extraAttributes,
					'class="rounded-lg border border-slate-700 bg-slate-950/60 px-3 py-2 text-slate-100"',
				];
				const attributeString = attributeList.filter(Boolean).join(' ');

				return `
					<label class="flex flex-col gap-1 text-sm">
						<span class="font-medium text-slate-200">${escapeHtml(field.label)}</span>
						<input ${attributeString} />
						${helpText}
					</label>
				`;
			})
			.join('');

		fieldsContainer.innerHTML = `
			${description}
			${tokenHelp}
			<div class="space-y-3">${fieldsHtml}</div>
		`;
	};

	const lockKeyInput = (templateKey) => {
		if (!keyInput) return;
		const definition = providerCatalog[templateKey];
		const shouldLock = Boolean(definition) && !isEdit;
		keyInput.readOnly = shouldLock || isEdit;
		keyInput.classList.toggle('cursor-not-allowed', keyInput.readOnly);
		if (definition && !isEdit) {
			keyInput.value = definition.key;
		}
	};

	lockKeyInput(activeTemplate);
	renderConfigFields();

	if (templateSelect) {
			templateSelect.addEventListener('change', () => {
			captureCurrentConfig();
			activeTemplate = templateSelect.value;
			lockKeyInput(activeTemplate);
			const def = providerCatalog[activeTemplate];
			if (def && nameInput && !isEdit) {
				const current = nameInput.value.trim();
				const suggested = def?.defaultName ?? def?.label;
				if (!current || current === providerCatalog[initialTemplate]?.defaultName) {
					nameInput.value = suggested ?? current;
				}
				nameInput.placeholder = suggested ?? '';
			}
			renderConfigFields();
		});
	}

	closeBtn?.addEventListener('click', () => {
		els.modal.close();
	});

	form?.addEventListener('submit', async (event) => {
		event.preventDefault();
		captureCurrentConfig();
		const formData = new FormData(form);
		let selectedTemplate = activeTemplate;
		if (!provider && templateSelect) {
			selectedTemplate = templateSelect.value;
		}
		const definition = providerCatalog[selectedTemplate] ?? null;
		const existingConfig = provider && provider.key === selectedTemplate && provider.config && typeof provider.config === 'object'
			? { ...provider.config }
			: {};
		let configPayload = { ...existingConfig };

		if (definition) {
			const values = tempConfig[selectedTemplate] ?? {};
			const missingField = definition.fields.find((field) => {
				const rawValue = values[field.name] ?? '';
				const stringValue = typeof rawValue === 'string' ? rawValue : String(rawValue ?? '');
				const comparable = field.type === 'password' ? stringValue : stringValue.trim();
				return field.required && comparable === '';
			});
			if (missingField) {
				showToast(`${missingField.label} is required.`, 'error');
				return;
			}

			const invalidNumberField = definition.fields.find((field) => {
				if (field.type !== 'number') return false;
				const rawValue = values[field.name] ?? '';
				const trimmed = String(rawValue ?? '').trim();
				if (trimmed === '') {
					return false;
				}
				if (!/^\d+$/.test(trimmed)) {
					return true;
				}
				const numeric = Number.parseInt(trimmed, 10);
				return Number.isNaN(numeric) || numeric <= 0;
			});
			if (invalidNumberField) {
				showToast(`${invalidNumberField.label} must be a positive whole number.`, 'error');
				return;
			}

			configPayload = { ...existingConfig };
			definition.fields.forEach((field) => {
				const rawValue = values[field.name] ?? '';
				if (field.type === 'password') {
					configPayload[field.name] = String(rawValue);
					return;
				}
				if (field.type === 'number') {
					const trimmed = String(rawValue ?? '').trim();
					if (trimmed === '') {
						delete configPayload[field.name];
					} else {
						configPayload[field.name] = Number.parseInt(trimmed, 10);
					}
					return;
				}
				configPayload[field.name] = String(rawValue ?? '').trim();
			});
		}

		let providerKey = String(formData.get('key') || '').trim();
		if ((definition && !isEdit) || (definition && provider)) {
			providerKey = definition.key;
		}
		if (providerKey === '') {
			showToast('Provider key is required.', 'error');
			return;
		}

		if (formData.get('debug') !== null) {
			configPayload.debug = true;
		} else {
			delete configPayload.debug;
		}

		const payload = {
			key: provider?.key ?? providerKey,
			name: String(formData.get('name') || '').trim() || (definition?.defaultName ?? definition?.label ?? providerKey),
			enabled: formData.get('enabled') !== null,
			config: configPayload,
		};

		try {
			if (isEdit && provider) {
				await fetchJson(`${API.providerUpdate}?id=${provider.id}`, {
					method: 'PATCH',
					body: JSON.stringify(payload),
				});
				showToast('Provider updated.', 'success');
			} else {
				await fetchJson(API.providerCreate, {
					method: 'POST',
					body: JSON.stringify({ ...payload, key: payload.key }),
				});
				showToast('Provider created.', 'success');
			}
			els.modal.close();
			await loadProviders();
		} catch (error) {
			showToast(messageFromError(error), 'error');
		}
	});

	els.modal.showModal();
}

async function confirmProviderDelete(providerId) {
	if (!window.confirm('Delete this provider?')) return;
	try {
		await fetchJson(`${API.providerDelete}?id=${providerId}`, { method: 'DELETE' });
		showToast('Provider deleted.', 'success');
		await loadProviders();
	} catch (error) {
		showToast(messageFromError(error), 'error');
	}
}

async function testProvider(providerId) {
	try {
		const response = await fetchJson(`${API.providerTest}?id=${providerId}`, { method: 'POST' });
		const message = response?.message ?? 'Provider test succeeded.';
		showToast(message, 'success');
	} catch (error) {
		showToast(`Provider test failed: ${messageFromError(error)}`, 'error');
	}
}

function showFormError(message) {
	if (!els.loginError) return;
	els.loginError.textContent = message;
	toggleElement(els.loginError, true);
}

function normalizeSearchResults(items) {
	return items.map((item) => {
		const externalId = typeof item?.id === 'string' && item.id !== ''
			? item.id
			: typeof item?.external_id === 'string' && item.external_id !== ''
				? item.external_id
				: typeof item?.ident === 'string' && item.ident !== ''
					? item.ident
					: crypto.randomUUID();
		const cacheKey = `${item.provider ?? 'unknown'}:${externalId}`;
		const videoWidth = item.video_width ?? item.width ?? null;
		const videoHeight = item.video_height ?? item.height ?? null;
		const videoFps = item.video_fps ?? item.fps ?? null;
		const audioChannels = item.audio_channels ?? null;
		const audioLanguage = item.audio_language ?? item.language ?? null;
		const bitrateKbps = item.bitrate_kbps ?? (item.bitrate ? Number(item.bitrate) / 1000 : null);
		const sourceEntry = item?.source?.source_entry ?? null;
		const rawPath = typeof item?.path === 'string' && item.path !== ''
			? item.path
			: (typeof sourceEntry?.url === 'string' && sourceEntry.url !== '' ? sourceEntry.url : null);
		const normalizedPath = typeof rawPath === 'string' && rawPath !== '' ? normalizeKraskaPath(rawPath) : null;
		const kraIdent = typeof item?.kra_ident === 'string' && item.kra_ident !== ''
			? item.kra_ident
			: (typeof item?.source?.kra_ident === 'string' && item.source.kra_ident !== '' ? item.source.kra_ident : null);
		return {
			external_id: externalId,
			provider: item.provider ?? 'unknown',
			title: item.title ?? 'Untitled',
			duration_seconds: item.duration_seconds ?? null,
			size_bytes: item.size_bytes ?? item.size ?? 0,
			thumbnail: item.thumbnail ?? null,
			video_codec: item.video_codec ?? null,
			audio_codec: item.audio_codec ?? null,
			resolution: item.resolution ?? null,
			video_width: videoWidth,
			video_height: videoHeight,
			video_fps: videoFps,
			audio_channels: audioChannels,
			audio_language: audioLanguage,
			bitrate_kbps: bitrateKbps,
			kra_ident: kraIdent,
			path: normalizedPath,
			kraska_path: normalizedPath,
			source: item?.source ?? null,
			source_entry: sourceEntry,
			cacheKey,
		};
	});
}

function defaultProviders() {
	return Object.values(providerCatalog).map((definition) => ({
		id: definition.key,
		key: definition.key,
		name: definition.defaultName ?? definition.label,
		enabled: true,
		config: {},
	}));
}
