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
		applyProviderAlerts,
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

	els.kraskaProviderToggle?.addEventListener('click', (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const button = target.closest('[data-kraska-provider]');
		if (!(button instanceof HTMLElement)) return;
		setKraskaMode(button.dataset.kraskaProvider ?? 'kraska');
	});

	els.krask2RefreshBtn?.addEventListener('click', () => {
		loadKrask2Catalogs({ force: true });
	});

	els.krask2LoadBtn?.addEventListener('click', () => {
		loadKrask2Items();
	});

	els.krask2ItemsRefreshBtn?.addEventListener('click', () => {
		loadKrask2Items({ force: true });
	});

	els.krask2QueueBtn?.addEventListener('click', () => {
		void queueSelectedKrask2();
	});

	els.krask2CatalogSelect?.addEventListener('change', (event) => {
		const target = event.target;
		if (!(target instanceof HTMLSelectElement)) return;
		setKrask2SelectedCatalog(target.value);
	});

	els.krask2SearchInput?.addEventListener('keydown', (event) => {
		if (event.key !== 'Enter') return;
		event.preventDefault();
		loadKrask2Items();
	});

	els.krask2List?.addEventListener('click', (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const optionsBtn = target.closest('[data-krask2-options]');
		if (optionsBtn instanceof HTMLElement) {
			const index = Number.parseInt(optionsBtn.dataset.krask2Options ?? '', 10);
			if (Number.isFinite(index)) {
				event.preventDefault();
				openKrask2Options(index);
			}
			return;
		}
		const toggleBtn = target.closest('[data-krask2-episodes-toggle]');
		if (toggleBtn instanceof HTMLElement) {
			const index = Number.parseInt(toggleBtn.dataset.krask2EpisodesToggle ?? '', 10);
			if (Number.isFinite(index)) {
				event.preventDefault();
				toggleKrask2Episodes(index);
			}
			return;
		}
		const refreshBtn = target.closest('[data-krask2-episodes-refresh]');
		if (refreshBtn instanceof HTMLElement) {
			const index = Number.parseInt(refreshBtn.dataset.krask2EpisodesRefresh ?? '', 10);
			if (Number.isFinite(index)) {
				event.preventDefault();
				const item = state.krask2.items[index];
				if (item) {
					loadKrask2Episodes(item, index, { force: true });
				}
			}
			return;
		}
		const episodeBtn = target.closest('[data-krask2-episode-options]');
		if (episodeBtn instanceof HTMLElement) {
			const payload = episodeBtn.dataset.krask2EpisodeOptions ?? '';
			const [itemIndexRaw, episodeIndexRaw] = payload.split(':');
			const itemIndex = Number.parseInt(itemIndexRaw ?? '', 10);
			const episodeIndex = Number.parseInt(episodeIndexRaw ?? '', 10);
			if (Number.isFinite(itemIndex) && Number.isFinite(episodeIndex)) {
				event.preventDefault();
				openKrask2EpisodeOptions(itemIndex, episodeIndex);
			}
		}
	});

	els.krask2List?.addEventListener('change', (event) => {
		handleKrask2SelectionChange(event);
	});

	updateKraskaProviderToggle();
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

		if (target.matches('[data-provider-pause]')) {
			pauseProvider(providerId);
		}

		if (target.matches('[data-provider-resume]')) {
			resumeProvider(providerId);
		}
	});

	els.auditRefreshBtn?.addEventListener('click', () => loadAudit(true));
	els.auditLoadMore?.addEventListener('click', () => loadAudit(false));

	// Aria2 health check button
	const aria2Btn = document.getElementById('aria2-health-btn');
	aria2Btn?.addEventListener('click', () => runAria2HealthCheck());

	// Logs refresh button
	const logsRefreshBtn = document.getElementById('logs-refresh-btn');
	logsRefreshBtn?.addEventListener('click', () => refreshLogs(true));

	// Logs auto-refresh toggle
	const logsAuto = document.getElementById('logs-auto-refresh');
	logsAuto?.addEventListener('change', () => toggleLogsAutoRefresh());

	// Logs select change
	const logsSelect = document.getElementById('logs-select');
	logsSelect?.addEventListener('change', () => refreshLogs(true));

	// Lines change
	const logsLines = document.getElementById('logs-lines');
	logsLines?.addEventListener('change', () => refreshLogs(true));

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

	const aria2MaxSpeedRaw = (els.settingsAria2MaxSpeed?.value ?? '').trim();
	let aria2MaxSpeed = null;
	if (aria2MaxSpeedRaw !== '') {
		const parsedAria2MaxSpeed = Number.parseFloat(aria2MaxSpeedRaw);
		if (!Number.isNaN(parsedAria2MaxSpeed)) {
			aria2MaxSpeed = parsedAria2MaxSpeed;
		}
	}

	const kraskaBackoffRaw = (els.settingsKraskaBackoffMinutes?.value ?? '').trim();
	let kraskaBackoffSeconds = null;
	if (kraskaBackoffRaw !== '') {
		const parsedBackoff = Number.parseFloat(kraskaBackoffRaw);
		if (!Number.isNaN(parsedBackoff)) {
			kraskaBackoffSeconds = Math.round(parsedBackoff * 60);
		}
	}

	const krask2SpacingRaw = (els.settingsKrask2SpacingSeconds?.value ?? '').trim();
	let krask2SpacingSeconds = null;
	if (krask2SpacingRaw !== '') {
		const parsedSpacing = Number.parseInt(krask2SpacingRaw, 10);
		if (!Number.isNaN(parsedSpacing)) {
			krask2SpacingSeconds = Math.max(0, parsedSpacing);
		}
	}

	const kraskaDebugEnabled = els.settingsKraskaDebugEnabled?.checked ?? false;

	const payload = {
		app: {
			base_url: (els.settingsBaseUrl?.value ?? '').trim(),
			max_active_downloads: maxDownloads,
			min_free_space_gb: minFreeSpace,
			default_search_limit: Number.parseInt(els.settingsDefaultSearchLimit?.value ?? '', 10) || null,
		},
		aria2: {
			max_speed_mb_s: aria2MaxSpeed,
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
			kraska_error_backoff_seconds: kraskaBackoffSeconds,
			krask2_download_spacing_seconds: krask2SpacingSeconds,
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
		els.settingsAria2MaxSpeed,
		els.settingsKraskaMenuCacheTtl,
		els.settingsKraskaBackoffMinutes,
		els.settingsKrask2SpacingSeconds,
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
		if (els.settingsAria2MaxSpeed) {
			const maxSpeed = settings?.aria2?.max_speed_mb_s;
			if (maxSpeed === null || maxSpeed === undefined) {
				els.settingsAria2MaxSpeed.value = '';
			} else {
				els.settingsAria2MaxSpeed.value = String(maxSpeed);
			}
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
		if (els.settingsKraskaBackoffMinutes) {
			const backoffSeconds = settings?.providers?.kraska_error_backoff_seconds;
			if (backoffSeconds === null || backoffSeconds === undefined) {
				els.settingsKraskaBackoffMinutes.value = '';
			} else {
				const minutes = backoffSeconds / 60;
				const roundedMinutes = Math.round(minutes * 10) / 10;
				els.settingsKraskaBackoffMinutes.value = Number.isFinite(roundedMinutes) ? String(roundedMinutes) : '';
			}
		}
		if (els.settingsKrask2SpacingSeconds) {
			const spacingSeconds = settings?.providers?.krask2_download_spacing_seconds;
			if (spacingSeconds === null || spacingSeconds === undefined) {
				els.settingsKrask2SpacingSeconds.value = '';
			} else {
				els.settingsKrask2SpacingSeconds.value = String(spacingSeconds);
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
		loadStats(),
		loadStorage(),
	];

	if (state.isAdmin) {
		tasks.push(loadUsers());
		tasks.push(loadSettings());
	}

	Promise.all(tasks).finally(() => {
		startStorageAutoRefresh();
		if (state.isAdmin) {
			loadProviderStatuses();
		}
		loadStats();
	});
}

function resetState() {
	stopStorageAutoRefresh();
	stopJobStream();
	state.user = null;
	state.providers = [];
	state.providersLoaded = false;
	state.users = [];
	state.searchResults = [];
	state.selectedSearch.clear();
	state.jobs = [];
	state.storage = [];
	state.storageUpdatedAt = null;
	state.isAdmin = false;
	state.usersLoading = false;
	state.jobNotifications.clear();
	state.providerAlerts = [];
	state.storageIntervalId = null;
	state.auditLogs = [];
	state.auditCursor = null;
	state.auditLoading = false;
	state.settings = null;
	state.settingsLoading = false;
	state.settingsSaving = false;
	state.settingsError = null;
	state.jobStreamConnectedAt = null;
	state.jobStreamVisibleIds = new Set();
	state.jobStreamVisibleKey = '';
	if (state.jobStreamReloadTimeout) {
		clearTimeout(state.jobStreamReloadTimeout);
	}
	state.jobStreamReloadTimeout = null;
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
	state.kraska.variantsCache = null;
	state.kraska.mode = 'kraska';
	state.krask2.manifest = null;
	state.krask2.catalogs = [];
	state.krask2.catalogsLoading = false;
	state.krask2.catalogsError = null;
	state.krask2.catalogCache = null;
	state.krask2.selectedCatalogKey = null;
	state.krask2.items = [];
	state.krask2.itemsLoading = false;
	state.krask2.itemsError = null;
	state.krask2.itemsRequested = false;
	state.krask2.searchTerm = '';
	state.krask2.lastLoadedSearch = '';
	state.krask2.meta = {};
	state.krask2.selected = new Map();
	state.krask2.queueLabel = '';
	state.krask2.itemsCache = null;
	applyDefaultSearchLimit(true);
	applyProviderAlerts([]);
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
		initLogsUI();
	}

	if (view === 'settings' && state.isAdmin) {
		loadSettings(true);
	}

	if (view === 'kraska') {
		ensureKraskaLoaded();
	}

	if (view === 'queue') {
		loadJobs();
	} else {
		stopJobStream();
	}
}

// --- Aria2 Health & Logs ---
let logsAutoRefreshId = null;

function runAria2HealthCheck() {
	const container = document.getElementById('aria2-health');
	if (!container) return;
	container.classList.remove('hidden');
	container.textContent = 'Checking aria2…';
	fetchJson(API.aria2Health + '?t=' + Date.now(), { cache: 'no-store' })
		.then((response) => {
			const aria = response?.checks?.aria2 ?? {};
			const db = response?.checks?.database ?? {};
			const runtime = response?.checks?.runtime ?? {};
			const status = response?.status ?? 'unknown';
			container.innerHTML = renderHealthStatus({ status, aria, db, runtime, checked_at: response?.checked_at });
		})
		.catch((error) => {
			container.innerHTML = `<div class="text-xs text-rose-300">${escapeHtml(messageFromError(error))}</div>`;
		});
}

function renderHealthStatus(payload) {
	const parts = [];
	const label = (title, body, variant = 'ok') => `<div class="flex flex-col gap-1 rounded-lg border ${variant === 'ok' ? 'border-emerald-600/40 bg-emerald-500/5' : 'border-rose-600/40 bg-rose-500/5'} p-3"><div class="text-xs font-semibold uppercase tracking-wide text-slate-300">${escapeHtml(title)}</div>${body}</div>`;
	const aria = payload.aria ?? {};
	const ariaOk = aria.status === 'ok';
	const ariaBody = ariaOk
		? `<div class="text-xs text-slate-300">Version: <span class="font-mono">${escapeHtml(String(aria.version ?? 'n/a'))}</span><br/>Features: ${Array.isArray(aria.enabled_features) && aria.enabled_features.length > 0 ? escapeHtml(aria.enabled_features.join(', ')) : '—'}</div>`
		: `<div class="text-xs text-rose-300">${escapeHtml(String(aria.message ?? 'Unavailable'))}</div>`;
	parts.push(label('Aria2', ariaBody, ariaOk ? 'ok' : 'error'));
	const db = payload.db ?? {};
	const dbOk = db.status === 'ok';
	const dbBody = dbOk
		? `<div class="text-xs text-slate-300">Latency: ${escapeHtml(String(db.latency_ms ?? 'n/a'))} ms</div>`
		: `<div class="text-xs text-rose-300">${escapeHtml(String(db.message ?? 'Error'))}</div>`;
	parts.push(label('Database', dbBody, dbOk ? 'ok' : 'error'));
	const runtime = payload.runtime ?? {};
	parts.push(label('Runtime', `<div class="text-xs text-slate-300">PHP ${escapeHtml(String(runtime.php_version ?? ''))}</div>`));
	const checkedAt = payload.checked_at ? `<div class="text-[10px] text-slate-500">Checked ${escapeHtml(formatRelativeTime(payload.checked_at))}</div>` : '';
	return `<div class="grid gap-2 sm:grid-cols-3">${parts.join('')}</div>${checkedAt}`;
}

function initLogsUI() {
	const wrapper = document.getElementById('logs-wrapper');
	if (!wrapper) return;
	wrapper.classList.remove('hidden');
	loadLogList();
}

async function loadLogList() {
	const select = document.getElementById('logs-select');
	if (!(select instanceof HTMLSelectElement)) return;
	try {
		const response = await fetchJson(API.logs);
		const entries = Array.isArray(response?.data) ? response.data : [];
		select.innerHTML = entries
			.map((e) => `<option value="${escapeHtml(String(e.name))}">${escapeHtml(String(e.name))}${Number.isFinite(e.size_bytes) ? ` (${formatRelativeSize(e.size_bytes)})` : ''}</option>`)
			.join('');
		refreshLogs(true);
	} catch (error) {
		const errorEl = document.getElementById('logs-error');
		if (errorEl) {
			errorEl.textContent = messageFromError(error);
			errorEl.classList.remove('hidden');
		}
	}
}

function refreshLogs(force = false) {
	const select = document.getElementById('logs-select');
	const linesInput = document.getElementById('logs-lines');
	const content = document.getElementById('logs-content');
	const errorEl = document.getElementById('logs-error');
	if (!(select instanceof HTMLSelectElement) || !content) return;
	const file = select.value;
	const linesRaw = linesInput instanceof HTMLInputElement ? Number.parseInt(linesInput.value, 10) : 120;
	const lines = Number.isFinite(linesRaw) ? Math.max(10, Math.min(linesRaw, 500)) : 120;
	if (linesInput instanceof HTMLInputElement) {
		linesInput.value = String(lines);
	}
	if (errorEl) {
		errorEl.classList.add('hidden');
		errorEl.textContent = '';
	}
	content.textContent = 'Loading…';
	fetchJson(`${API.logs}?file=${encodeURIComponent(file)}&lines=${lines}&t=${Date.now()}`)
		.then((response) => {
			const linesArr = Array.isArray(response?.data) ? response.data : [];
			content.textContent = linesArr.join('\n');
		})
		.catch((error) => {
			content.textContent = '';
			if (errorEl) {
				errorEl.textContent = messageFromError(error);
				errorEl.classList.remove('hidden');
			}
		});
}

function toggleLogsAutoRefresh() {
	const auto = document.getElementById('logs-auto-refresh');
	if (!(auto instanceof HTMLInputElement)) return;
	if (logsAutoRefreshId !== null) {
		window.clearInterval(logsAutoRefreshId);
		logsAutoRefreshId = null;
	}
	if (auto.checked) {
		logsAutoRefreshId = window.setInterval(() => refreshLogs(false), 10_000);
		refreshLogs(false);
	}
}

function ensureKraskaLoaded() {
	if (!state.user) return;
	if (state.kraska.mode === 'krask2') {
		ensureKrask2Initialized();
		return;
	}
	ensureKraskaMenuReady();
}

function ensureKraskaMenuReady() {
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
		const cacheMeta = normalizeCacheMeta(response?.cache ?? null);
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

function formatCacheAge(meta) {
	if (!meta) return '';
	if (typeof meta.age_seconds === 'number' && Number.isFinite(meta.age_seconds)) {
		return formatCacheAgeFromSeconds(meta.age_seconds);
	}
	if (meta.fetched_at) {
		return formatRelativeTime(meta.fetched_at);
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

function buildCacheMetaText(meta, cachedLabel = 'Cached result', freshLabel = 'Fresh result') {
	if (!meta) {
		return '';
	}
	const label = meta.hit ? cachedLabel : freshLabel;
	const ageLabel = formatCacheAge(meta);
	return ageLabel ? `${label} • ${ageLabel}` : label;
}

function updateCacheMetaElement(element, meta, options = {}) {
	if (!element) return;
	const display = options.display ?? 'inline-flex';
	if (!meta) {
		element.textContent = '';
		delete element.dataset.variant;
		toggleElement(element, false);
		return;
	}
	const cachedLabel = options.cachedLabel ?? 'Cached result';
	const freshLabel = options.freshLabel ?? 'Fresh result';
	const text = buildCacheMetaText(meta, cachedLabel, freshLabel);
	if (text === '') {
		element.textContent = '';
		delete element.dataset.variant;
		toggleElement(element, false);
		return;
	}
	element.textContent = text;
	element.dataset.variant = meta.hit ? 'cached' : 'fresh';
	toggleElement(element, true, display);
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

function normalizeCacheMeta(meta) {
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
		ttl_seconds: Number.isFinite(ttlRaw) && ttlRaw >= 0 ? ttlRaw : null,
		refreshable: meta.refreshable !== undefined ? Boolean(meta.refreshable) : true,
	};
}

function renderKraskaCacheMeta() {
	updateCacheMetaElement(els.kraskaCacheMeta, state.kraska.cache, {
		cachedLabel: 'Cached result',
		freshLabel: 'Fresh result',
	});
}

function renderKrask2CatalogCacheMeta() {
	if (state.krask2.catalogsLoading || !Array.isArray(state.krask2.catalogs) || state.krask2.catalogs.length === 0) {
		updateCacheMetaElement(els.krask2CatalogCacheMeta, null);
		return;
	}
	const meta = state.krask2.catalogCache ?? { hit: false, fetched_at: null, age_seconds: null };
	updateCacheMetaElement(els.krask2CatalogCacheMeta, meta, {
		cachedLabel: 'Manifest cached',
		freshLabel: 'Manifest fetched live',
		display: 'block',
	});
}

function renderKrask2ItemsCacheMeta() {
	if (!state.krask2.itemsRequested || state.krask2.itemsLoading) {
		updateCacheMetaElement(els.krask2ItemsCacheMeta, null);
		return;
	}
	const meta = state.krask2.itemsCache ?? { hit: false, fetched_at: null, age_seconds: null };
	updateCacheMetaElement(els.krask2ItemsCacheMeta, meta, {
		cachedLabel: 'Catalog cached',
		freshLabel: 'Catalog fetched live',
		display: 'block',
	});
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
		if (state.currentView === 'queue') {
			loadJobs();
		}
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

function buildKrask2JobMetadata(context, variant) {
	if (!context || (String(context.provider ?? '')).toLowerCase() !== 'krask2') {
		return null;
	}
	const sourceItem = context.sourceItem && typeof context.sourceItem === 'object' ? context.sourceItem : null;
	if (!sourceItem) {
		return null;
	}
	const parentItem = context.sourceParent && typeof context.sourceParent === 'object' ? context.sourceParent : null;
	const catalog = summarizeKrask2Catalog(context.catalog ?? null);
	const metadata = {
		source: 'krask2',
	};
	if (catalog) {
		metadata.catalog = catalog;
	}
	const trailLabels = buildKrask2TrailLabels(catalog, sourceItem, parentItem);
	if (trailLabels.length > 0) {
		metadata.menu = {
			trail_labels: trailLabels,
		};
	}
	const languages = extractKrask2Languages(sourceItem, variant);
	const season = extractKrask2Number(sourceItem.season ?? parentItem?.season);
	const episode = extractKrask2Number(sourceItem.episode);
	const itemMeta = {};
	if (languages.length > 0) {
		itemMeta.languages = languages;
	}
	if (Number.isFinite(sourceItem.year) && Number(sourceItem.year) > 0) {
		itemMeta.year = Number(sourceItem.year);
	}
	if (season !== null) {
		itemMeta.season = season;
	}
	if (episode !== null) {
		itemMeta.episode = episode;
	}
	const itemPayload = {
		id: sourceItem.id ?? null,
		type: typeof sourceItem.type === 'string' ? sourceItem.type : null,
		title: typeof sourceItem.title === 'string' ? sourceItem.title : context.label ?? null,
		label: typeof sourceItem.label === 'string' ? sourceItem.label : context.label ?? null,
	};
	if (isNonEmptyObject(itemMeta)) {
		itemPayload.meta = itemMeta;
	}
	metadata.item = itemPayload;
	const hints = buildKrask2Hints({
		sourceItem,
		parentItem,
		languages,
		season,
		episode,
	});
	if (isNonEmptyObject(hints)) {
		metadata.hints = hints;
	}
	const compacted = compactKraskaMetadata(metadata);
	return isNonEmptyObject(compacted) ? compacted : null;
}

function buildKrask2TrailLabels(catalog, sourceItem, parentItem) {
	const labels = [];
	const catalogLabel = catalog && typeof catalog.name === 'string' ? catalog.name : null;
	if (catalogLabel && catalogLabel.trim() !== '') {
		labels.push(catalogLabel.trim());
	}
	const type = typeof sourceItem.type === 'string' ? sourceItem.type : '';
	if (type === 'episode' || type === 'series') {
		const seriesTitle = deriveKrask2SeriesTitle(sourceItem, parentItem);
		if (seriesTitle) {
			labels.push(seriesTitle);
		}
		const seasonNumber = extractKrask2Number(sourceItem.season ?? parentItem?.season);
		if (seasonNumber !== null) {
			labels.push(`Season ${String(seasonNumber).padStart(2, '0')}`);
		}
	} else {
		const title = typeof sourceItem.title === 'string' ? sourceItem.title.trim() : '';
		if (title !== '') {
			labels.push(title);
		}
	}
	return labels.filter((label, index, array) => label !== '' && array.indexOf(label) === index);
}

function deriveKrask2SeriesTitle(sourceItem, parentItem) {
	const parentTitle = parentItem && typeof parentItem.title === 'string' ? parentItem.title.trim() : '';
	if (parentTitle !== '') {
		return parentTitle;
	}
	const fromItem = typeof sourceItem.series_title === 'string' ? sourceItem.series_title : sourceItem.seriesTitle;
	if (typeof fromItem === 'string' && fromItem.trim() !== '') {
		return fromItem.trim();
	}
	if ((sourceItem.type === 'series' || sourceItem.type === 'episode') && typeof sourceItem.title === 'string' && sourceItem.title.trim() !== '') {
		return sourceItem.title.trim();
	}
	return null;
}

function extractKrask2Number(value) {
	if (value === undefined || value === null) {
		return null;
	}
	const numeric = typeof value === 'number' ? value : Number.parseInt(String(value), 10);
	return Number.isFinite(numeric) && numeric > 0 ? numeric : null;
}

function extractKrask2Languages(sourceItem, variant) {
	if (Array.isArray(sourceItem?.languages) && sourceItem.languages.length > 0) {
		return normalizeLanguageTokenList(sourceItem.languages, 3);
	}
	const variantLabel = typeof variant?.language === 'string' ? variant.language : null;
	if (variantLabel && variantLabel.trim() !== '') {
		return normalizeLanguageTokensFromString(variantLabel, 3);
	}
	return [];
}

function buildKrask2Hints({ sourceItem, parentItem, languages, season, episode }) {
	const hints = {};
	const seriesTitle = deriveKrask2SeriesTitle(sourceItem, parentItem);
	if (seriesTitle) {
		hints.series_title = seriesTitle;
	}
	if (season !== null) {
		hints.season = season;
		hints.season_label = `Season ${String(season).padStart(2, '0')}`;
	}
	if (episode !== null) {
		hints.episode = episode;
	}
	if ((sourceItem.type === 'episode' || episode !== null) && typeof sourceItem.title === 'string' && sourceItem.title.trim() !== '') {
		hints.episode_title = sourceItem.title.trim();
	}
	if (languages.length > 0) {
		hints.languages = languages.slice(0, 2);
		const suffix = buildLanguageSuffixFromList(languages);
		if (suffix) {
			hints.language_suffix = suffix;
		}
	}
	return hints;
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
	const directExternal = typeof item?.external_id === 'string' && item.external_id !== ''
		? item.external_id
		: typeof item?.externalId === 'string' && item.externalId !== ''
			? item.externalId
			: null;
	const externalId = directExternal ?? preferredPath ?? preferredIdent;
	if (!externalId) {
		showToast('This entry does not expose downloadable variants.', 'warning');
		return;
	}
	const providerKey = typeof item?.provider === 'string' && item.provider !== '' ? item.provider : 'kraska';

	state.kraska.selectedItem = {
		label: item?.label ?? item?.title ?? 'Untitled',
		ident: preferredIdent,
		path: preferredPath,
		provider: providerKey,
		externalId,
		kra_ident: typeof item?.kra_ident === 'string' && item.kra_ident !== '' ? item.kra_ident : null,
	};
	if (item && typeof item === 'object' && item !== null) {
		['cacheKey', 'contextType', 'sourceItem', 'sourceParent', 'catalog', 'searchIndex', 'kra_ident'].forEach((key) => {
			if (key in item) {
				state.kraska.selectedItem[key] = item[key];
			}
		});
	}
	state.kraska.variants = [];
	state.kraska.variantsLoading = true;
	state.kraska.variantsError = null;
	state.kraska.variantQueueing = false;
	state.kraska.variantsCache = null;

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
			state.kraska.variantsCache = null;
		},
		{ once: true }
	);

	void loadKraskaVariantOptions();
}

async function loadKraskaVariantOptions(options = {}) {
	if (!state.user) {
		showToast('Please sign in first.', 'warning');
		return;
	}
	const context = state.kraska.selectedItem;
	if (!context || !context.externalId) {
		return;
	}
	const providerKey = context.provider ?? 'kraska';
	const endpoint = providerKey === 'krask2' ? API.krask2Options : API.kraskaOptions;
	const params = new URLSearchParams({ external_id: context.externalId });
	const force = options.force === true;
	if (force && providerKey === 'krask2') {
		params.set('refresh', '1');
	}
	state.kraska.variantsLoading = true;
	state.kraska.variantsError = null;
	state.kraska.variantsCache = null;
	renderKraskaOptionsModal();

	try {
		const response = await fetchJson(`${endpoint}?${params.toString()}`);
		const variants = normalizeKraskaVariants(response?.data ?? []);
		state.kraska.variants = variants;
		state.kraska.variantsLoading = false;
		state.kraska.variantsCache = normalizeCacheMeta(response?.cache ?? null);
		if (variants.length === 0) {
			state.kraska.variantsError = 'No download options available for this entry.';
		}
	} catch (error) {
		state.kraska.variantsLoading = false;
		state.kraska.variantsError = messageFromError(error);
		state.kraska.variantsCache = null;
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
 	const isKrask2 = (context.provider ?? 'kraska') === 'krask2';
 	const cacheLabel = isKrask2
 		? buildCacheMetaText(state.kraska.variantsCache ?? { hit: false }, 'Streams cached', 'Streams fetched live')
 		: '';
 	const refreshButton = isKrask2
 		? `<button type="button" data-krask2-options-refresh class="rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-1 text-xs font-semibold text-slate-200 transition hover:border-brand-400/60 hover:text-brand-200 disabled:cursor-not-allowed disabled:opacity-50" ${loading ? 'disabled' : ''}>Refresh streams</button>`
 		: '';
 	const cacheMetaRow = isKrask2
 		? `<div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">${cacheLabel ? `<span class="rounded-full border border-slate-800/60 bg-slate-900/40 px-2 py-0.5 text-slate-300">${escapeHtml(cacheLabel)}</span>` : '<span class="text-slate-500">Cache metadata unavailable</span>'}${refreshButton}</div>`
 		: '';

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
				${cacheMetaRow}
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

	const refreshBtn = els.modal.querySelector('[data-krask2-options-refresh]');
	refreshBtn?.addEventListener('click', () => {
		if (!state.kraska.variantsLoading) {
			void loadKraskaVariantOptions({ force: true });
		}
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

	state.kraska.variantQueueing = true;
	if (button instanceof HTMLButtonElement) {
		button.setAttribute('disabled', 'true');
		button.textContent = 'Queuing…';
	}

	try {
		const payloadItem = buildQueuePayloadFromVariant(context, variant);
		await fetchJson(API.queue, {
			method: 'POST',
			body: JSON.stringify({
				items: [payloadItem],
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
		if (state.currentView === 'queue') {
			loadJobs();
		}
	} catch (error) {
		showToast(messageFromError(error), 'error');
		state.kraska.variantQueueing = false;
		if (button instanceof HTMLButtonElement) {
			button.removeAttribute('disabled');
			button.textContent = 'Queue this option';
		}
	}
}

function setKraskaMode(mode) {
	const available = getAvailableBrowseProviders();
	let normalized = mode === 'krask2' ? 'krask2' : 'kraska';
	if (available.length > 0 && !available.includes(normalized)) {
		normalized = available[0];
	} else if (available.length === 0) {
		normalized = 'kraska';
	}

	if (state.kraska.mode === normalized) {
		if (normalized === 'krask2') {
			ensureKrask2Initialized();
		} else {
			ensureKraskaMenuReady();
		}
		return;
	}

	state.kraska.mode = normalized;
	updateKraskaProviderToggle();
	if (normalized === 'krask2') {
		state.kraska.selected.clear();
		updateKraskaQueueButton();
		ensureKrask2Initialized();
	} else {
		ensureKraskaMenuReady();
	}
}

function getAvailableBrowseProviders() {
	const providerKeys = ['kraska', 'krask2'];
	if (!state.providersLoaded) {
		return providerKeys;
	}

	if (!Array.isArray(state.providers) || state.providers.length === 0) {
		return [];
	}

	const configuredKeys = new Set(state.providers.map((provider) => provider.key));
	return providerKeys.filter((key) => configuredKeys.has(key));
}

function updateKraskaProviderToggle() {
	const available = getAvailableBrowseProviders();
	let mode = state.kraska.mode === 'krask2' ? 'krask2' : 'kraska';
	if (available.length > 0 && !available.includes(mode)) {
		mode = available[0];
		state.kraska.mode = mode;
	} else if (available.length === 0) {
		mode = 'kraska';
		state.kraska.mode = mode;
	}

	if (els.kraskaProviderToggle) {
		const buttons = els.kraskaProviderToggle.querySelectorAll('[data-kraska-provider]');
		let visibleCount = 0;
		buttons.forEach((button) => {
			const providerKey = button.dataset.kraskaProvider === 'krask2' ? 'krask2' : 'kraska';
			const isAvailable = !state.providersLoaded || available.includes(providerKey);
			button.classList.toggle('hidden', !isAvailable);
			if (isAvailable) {
				visibleCount += 1;
			}
			const isActive = providerKey === mode;
			button.classList.toggle('text-slate-100', isActive);
			button.classList.toggle('text-slate-300/70', !isActive);
			button.classList.toggle('bg-slate-900/40', isActive);
		});
		toggleElement(els.kraskaProviderToggle, visibleCount > 0, 'inline-flex');
	}
	toggleElement(els.kraskaMenuControls, mode === 'kraska');
	toggleElement(els.kraskaMenuView, mode === 'kraska');
	toggleElement(els.krask2View, mode === 'krask2');
}

function ensureKrask2Initialized() {
	if (!state.user) return;
	if ((!Array.isArray(state.krask2.catalogs) || state.krask2.catalogs.length === 0) && !state.krask2.catalogsLoading) {
		loadKrask2Catalogs();
	} else {
		renderKrask2View();
	}
}

async function loadKrask2Catalogs(options = {}) {
	if (!state.user) {
		showToast('Please sign in first.', 'warning');
		return;
	}
	const force = options.force === true;
	if (!force && (state.krask2.catalogsLoading || (Array.isArray(state.krask2.catalogs) && state.krask2.catalogs.length > 0))) {
		renderKrask2View();
		return;
	}
	state.krask2.catalogsLoading = true;
	state.krask2.catalogsError = null;
	state.krask2.catalogCache = null;
	renderKrask2View();

	try {
		const params = new URLSearchParams({ t: String(Date.now()) });
		if (force) {
			params.set('refresh', '1');
		}
		const response = await fetchJson(`${API.krask2Catalogs}?${params.toString()}`);
		const manifest = response?.data?.manifest ?? null;
		const catalogs = Array.isArray(response?.data?.catalogs) ? response.data.catalogs : [];
		const cacheMeta = normalizeCacheMeta(response?.cache ?? null);
		state.krask2.manifest = manifest;
		state.krask2.catalogs = catalogs;
		state.krask2.catalogCache = cacheMeta;
		const firstKey = catalogs.map((catalog) => krask2CatalogKey(catalog)).find((key) => key !== '') ?? null;
		const currentKey = state.krask2.selectedCatalogKey;
		if (!currentKey || !catalogs.some((catalog) => krask2CatalogKey(catalog) === currentKey)) {
			state.krask2.selectedCatalogKey = firstKey;
		}
		if (force) {
			showToast('Catalogs refreshed.', 'success');
		}
	} catch (error) {
		state.krask2.catalogsError = messageFromError(error);
		state.krask2.catalogs = [];
		state.krask2.selectedCatalogKey = null;
		state.krask2.items = [];
		state.krask2.meta = {};
		state.krask2.itemsRequested = false;
		state.krask2.catalogCache = null;
	} finally {
		state.krask2.catalogsLoading = false;
		renderKrask2View();
	}
}

function renderKrask2CatalogOptions() {
	const select = els.krask2CatalogSelect;
	if (!(select instanceof HTMLSelectElement)) return;
	const catalogs = Array.isArray(state.krask2.catalogs) ? state.krask2.catalogs : [];
	if (catalogs.length === 0) {
		select.innerHTML = '<option value="">No catalogs available</option>';
		select.value = '';
		select.setAttribute('disabled', 'true');
		return;
	}
	select.removeAttribute('disabled');
	const options = catalogs
		.map((catalog) => {
			const key = krask2CatalogKey(catalog);
			const label = typeof catalog.name === 'string' && catalog.name.trim() !== '' ? catalog.name.trim() : catalog.id ?? 'Catalog';
			const typeLabel = typeof catalog.type === 'string' && catalog.type !== '' ? catalog.type.toUpperCase() : 'ANY';
			const selected = key !== '' && key === state.krask2.selectedCatalogKey ? 'selected' : '';
			return `<option value="${escapeHtml(key)}" ${selected}>${escapeHtml(`${label} (${typeLabel})`)}</option>`;
		})
		.join('');
	select.innerHTML = options;
	if (state.krask2.selectedCatalogKey) {
		select.value = state.krask2.selectedCatalogKey;
	} else {
		select.value = '';
	}
}

function krask2CatalogKey(catalog) {
	if (!catalog || typeof catalog !== 'object') return '';
	const type = typeof catalog.type === 'string' ? catalog.type.toLowerCase() : '';
	const id = typeof catalog.id === 'string' ? catalog.id : '';
	if (type === '' || id === '') return '';
	return `${type}|${id}`;
}

function getSelectedKrask2Catalog() {
	const key = state.krask2.selectedCatalogKey;
	if (!key) return null;
	return (state.krask2.catalogs ?? []).find((catalog) => krask2CatalogKey(catalog) === key) ?? null;
}

function summarizeKrask2Catalog(catalog) {
	if (!catalog || typeof catalog !== 'object') {
		return null;
	}
	const summary = {};
	if (typeof catalog.id === 'string' && catalog.id !== '') {
		summary.id = catalog.id;
	}
	if (typeof catalog.type === 'string' && catalog.type !== '') {
		summary.type = catalog.type.toLowerCase();
	}
	if (typeof catalog.name === 'string' && catalog.name.trim() !== '') {
		summary.name = catalog.name.trim();
	}
	return Object.keys(summary).length > 0 ? summary : null;
}

function setKrask2SelectedCatalog(value) {
	const resolved = typeof value === 'string' && value !== '' ? value : null;
	if (resolved) {
		const exists = (state.krask2.catalogs ?? []).some((catalog) => krask2CatalogKey(catalog) === resolved);
		state.krask2.selectedCatalogKey = exists ? resolved : null;
	} else {
		state.krask2.selectedCatalogKey = null;
	}
	state.krask2.items = [];
	state.krask2.meta = {};
	state.krask2.itemsRequested = false;
	state.krask2.itemsError = null;
	state.krask2.itemsCache = null;
	resetKrask2Selection();
	renderKrask2View();
}

function resetKrask2Selection() {
	state.krask2.selected = new Map();
	state.krask2.queueLabel = '';
	updateKrask2QueueButton();
}

async function loadKrask2Items(options = {}) {
	if (!state.user) {
		showToast('Please sign in first.', 'warning');
		return;
	}
	const catalog = getSelectedKrask2Catalog();
	if (!catalog) {
		showToast('Select a catalog first.', 'warning');
		return;
	}
	const searchTerm = typeof els.krask2SearchInput?.value === 'string' ? els.krask2SearchInput.value.trim() : '';
	state.krask2.searchTerm = searchTerm;
	state.krask2.itemsLoading = true;
	state.krask2.itemsError = null;
	state.krask2.itemsRequested = true;
	state.krask2.items = [];
	state.krask2.itemsCache = null;
	state.krask2.meta = {};
	resetKrask2Selection();
	renderKrask2View();
	const force = options.force === true;

	try {
		const params = new URLSearchParams({
			type: String(catalog.type ?? ''),
			id: String(catalog.id ?? ''),
		});
		if (searchTerm !== '') {
			params.set('search', searchTerm);
		}
		if (force) {
			params.set('refresh', '1');
		}
		const response = await fetchJson(`${API.krask2CatalogItems}?${params.toString()}`);
		const rawItems = Array.isArray(response?.data) ? response.data : [];
		const cacheMeta = normalizeCacheMeta(response?.cache ?? null);
		state.krask2.items = normalizeKrask2Items(rawItems, catalog);
		state.krask2.lastLoadedSearch = searchTerm;
		state.krask2.itemsCache = cacheMeta;
	} catch (error) {
		state.krask2.itemsError = messageFromError(error);
		state.krask2.items = [];
		state.krask2.itemsCache = null;
	} finally {
		state.krask2.itemsLoading = false;
		renderKrask2View();
	}
}

function normalizeKrask2Items(rawItems, catalog) {
	if (!Array.isArray(rawItems)) {
		return [];
	}
	return rawItems
		.map((item, index) => normalizeKrask2Item(item, catalog, index))
		.filter(Boolean);
}

function normalizeKrask2Item(meta, catalog, index) {
	if (!meta || typeof meta !== 'object') return null;
	const id = typeof meta.id === 'string' && meta.id !== '' ? meta.id : `catalog-${index}`;
	const title = typeof meta.name === 'string' && meta.name.trim() !== ''
		? meta.name.trim()
		: typeof meta.title === 'string' && meta.title.trim() !== ''
			? meta.title.trim()
			: null;
	if (!title) return null;
	const typeRaw = typeof meta.type === 'string' && meta.type !== '' ? meta.type.toLowerCase() : (typeof catalog?.type === 'string' ? catalog.type.toLowerCase() : 'movie');
	const description = typeof meta.description === 'string' && meta.description.trim() !== ''
		? meta.description.trim()
		: typeof meta.overview === 'string' && meta.overview.trim() !== ''
			? meta.overview.trim()
			: null;
	const poster = typeof meta.poster === 'string' && meta.poster !== ''
		? meta.poster
		: typeof meta.background === 'string' && meta.background !== ''
			? meta.background
			: typeof meta.logo === 'string' && meta.logo !== ''
				? meta.logo
				: null;
	const externalId = typeof meta.external_id === 'string' && meta.external_id !== '' ? meta.external_id : null;

	return {
		id,
		label: title,
		title,
		type: typeRaw,
		description,
		poster,
		catalogLabel: typeof catalog?.name === 'string' ? catalog.name : null,
		external_id: externalId,
		externalId,
		year: parseKrask2Year(meta.year ?? meta.releaseInfo ?? meta.releaseInfoShort ?? null),
		imdbRating: Number.isFinite(Number(meta.imdbRating ?? meta.rating)) ? Number(meta.imdbRating ?? meta.rating) : null,
		runtimeMinutes: parseKrask2RuntimeMinutes(meta.runtime ?? meta.runtimeMinutes ?? null),
		genres: normalizeKrask2Genres(meta.genres ?? meta.genre ?? null),
		languages: normalizeKrask2Languages(meta.languages ?? meta.language ?? meta.lang ?? null),
		provider: 'krask2',
		source: meta,
	};
}

function normalizeKrask2Genres(value) {
	if (Array.isArray(value)) {
		return value.map((genre) => (typeof genre === 'string' ? genre.trim() : '')).filter((genre) => genre !== '');
	}
	if (typeof value === 'string' && value.trim() !== '') {
		return value
			.split(/[,/]/)
			.map((part) => part.trim())
			.filter((part) => part !== '');
	}
	return [];
}

function normalizeKrask2Languages(value) {
	if (Array.isArray(value)) {
		return normalizeLanguageTokenList(value, 3);
	}
	if (typeof value === 'string' && value.trim() !== '') {
		const tokens = value
			.split(/[,/]/)
			.map((part) => part.trim())
			.filter((part) => part !== '');
		return normalizeLanguageTokenList(tokens, 3);
	}
	return [];
}

function parseKrask2Year(value) {
	const numeric = Number.parseInt(value, 10);
	if (Number.isFinite(numeric) && numeric > 1900) {
		return numeric;
	}
	if (typeof value === 'string') {
		const match = value.match(/(19|20)\d{2}/);
		if (match) {
			return Number.parseInt(match[0], 10);
		}
	}
	return null;
}

function parseKrask2RuntimeMinutes(value) {
	if (typeof value === 'number' && Number.isFinite(value)) {
		return value;
	}
	if (typeof value !== 'string' || value.trim() === '') {
		return null;
	}
	const numeric = Number.parseInt(value, 10);
	if (Number.isFinite(numeric)) {
		return numeric;
	}
	const isoMatch = value.match(/PT(?:(\d+)H)?(?:(\d+)M)?/i);
	if (isoMatch) {
		const hours = Number.parseInt(isoMatch[1] ?? '0', 10) || 0;
		const minutes = Number.parseInt(isoMatch[2] ?? '0', 10) || 0;
		return hours * 60 + minutes;
	}
	return null;
}

function renderKrask2View() {
	renderKrask2CatalogOptions();
	const errorMessage = state.krask2.itemsError ?? state.krask2.catalogsError;
	if (els.krask2Error) {
		if (errorMessage) {
			els.krask2Error.textContent = errorMessage;
			toggleElement(els.krask2Error, true);
		} else {
			toggleElement(els.krask2Error, false);
		}
	}
	const isLoading = Boolean(state.krask2.catalogsLoading || state.krask2.itemsLoading);
	toggleElement(els.krask2Loading, isLoading);
	const showEmpty = !isLoading && !errorMessage && state.krask2.itemsRequested && state.krask2.items.length === 0;
	toggleElement(els.krask2Empty, showEmpty);

	if (els.krask2List) {
		if (!Array.isArray(state.krask2.items) || state.krask2.items.length === 0) {
			els.krask2List.innerHTML = '';
		} else {
			els.krask2List.innerHTML = state.krask2.items.map((item, index) => renderKrask2Item(item, index)).join('');
			state.krask2.items.forEach((item, index) => {
				if (item.type === 'series') {
					renderKrask2Episodes(index);
				}
			});
		}
	}

	if (els.krask2LoadBtn) {
		const catalog = getSelectedKrask2Catalog();
		els.krask2LoadBtn.disabled = state.krask2.itemsLoading || !catalog;
		els.krask2LoadBtn.textContent = state.krask2.itemsLoading ? 'Loading…' : 'Load items';
	}

	if (els.krask2RefreshBtn) {
		els.krask2RefreshBtn.disabled = state.krask2.catalogsLoading;
		els.krask2RefreshBtn.textContent = state.krask2.catalogsLoading ? 'Refreshing…' : 'Refresh catalogs';
	}

	if (els.krask2ItemsRefreshBtn) {
		const catalog = getSelectedKrask2Catalog();
		const busy = state.krask2.itemsLoading;
		const ready = catalog && state.krask2.itemsRequested;
		els.krask2ItemsRefreshBtn.disabled = busy || !ready;
		els.krask2ItemsRefreshBtn.textContent = busy ? 'Refreshing…' : 'Refresh results';
	}

	renderKrask2CatalogCacheMeta();
	renderKrask2ItemsCacheMeta();
	updateKrask2QueueButton();
}

function renderKrask2Item(item, index) {
	const metaState = getKrask2MetaState(item.id);
	const description = item.description ? `<p class="text-sm text-slate-400">${escapeHtml(item.description)}</p>` : '';
	const chips = renderKrask2MetaChips(item);
	const poster = item.poster
		? `<img src="${escapeHtml(item.poster)}" alt="${escapeHtml(item.title)}" class="h-24 w-24 rounded-xl object-cover" loading="lazy" />`
		: '<div class="flex h-24 w-24 items-center justify-center rounded-xl bg-slate-800/70 text-3xl">🎬</div>';
	const actions = [];
	if (item.external_id) {
		actions.push(`<button type="button" data-krask2-options="${index}" class="rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:border-brand-400/60 hover:text-brand-200">Download options</button>`);
	}
	if (item.type === 'series') {
		const loading = metaState?.loading;
		const expanded = metaState?.expanded;
		let label = 'Show episodes';
		if (loading) {
			label = 'Loading…';
		} else if (expanded) {
			label = 'Hide episodes';
		}
		actions.push(`<button type="button" data-krask2-episodes-toggle="${index}" class="rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:border-brand-400/60 hover:text-brand-200 disabled:cursor-not-allowed disabled:opacity-50" ${loading ? 'disabled' : ''}>${escapeHtml(label)}</button>`);
	}
	const selectionControl = buildKrask2ItemSelectionControl(item, index);
	if (selectionControl) {
		actions.push(selectionControl);
	}
	const catalogLabel = item.catalogLabel ? `<span class="rounded-full border border-slate-800/60 bg-slate-950/50 px-2 py-0.5 text-[11px] uppercase tracking-wide text-slate-400">${escapeHtml(item.catalogLabel)}</span>` : '';
	const episodesContainer = item.type === 'series'
		? `<div data-krask2-episodes="${index}" class="mt-3 hidden space-y-2 rounded-xl border border-slate-800/60 bg-slate-900/40 p-4 text-sm"></div>`
		: '';

	return `
		<li class="rounded-2xl border border-slate-800/70 bg-slate-900/60 p-4">
			<div class="flex flex-col gap-4 md:flex-row">
				${poster}
				<div class="flex-1 space-y-2">
					<div class="flex flex-wrap items-center gap-2">
						<h4 class="text-lg font-semibold text-slate-100">${escapeHtml(item.title)}</h4>
						<span class="rounded-full border border-slate-800/60 bg-slate-950/50 px-2 py-0.5 text-[11px] uppercase tracking-wide text-slate-400">${escapeHtml(item.type)}</span>
						${catalogLabel}
					</div>
					${description}
					${chips}
					${actions.length > 0 ? `<div class="flex flex-wrap gap-2">${actions.join('')}</div>` : ''}
					${episodesContainer}
				</div>
			</div>
		</li>
	`;
}

function buildKrask2ItemSelectionControl(item, index) {
	const kind = item.type === 'series' ? 'series' : 'movie';
	if (kind === 'movie' && (!item.external_id || item.external_id === '')) {
		return '';
	}
	const key = buildKrask2SelectionKey(kind, item.id, null);
	const checked = isKrask2SelectionMarked(key) ? 'checked' : '';
	const label = kind === 'series' ? 'Select entire series' : 'Select movie';
	return `
		<label class="inline-flex items-center gap-2 rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-slate-200">
			<input type="checkbox" data-krask2-item-select="1" data-krask2-select-kind="${kind}" data-krask2-item-index="${index}" data-krask2-selection-key="${escapeHtml(key)}" class="h-4 w-4 rounded border-slate-600 bg-slate-800 text-brand-500 focus:ring-brand-500/60" ${checked} />
			<span>${escapeHtml(label)}</span>
		</label>
	`;
}

function renderKrask2MetaChips(item) {
	const chips = [];
	if (item.year) chips.push(String(item.year));
	if (item.runtimeMinutes) chips.push(formatDuration(item.runtimeMinutes * 60));
	if (Number.isFinite(item.imdbRating)) chips.push(`${Number(item.imdbRating).toFixed(1)}★`);
	if (Array.isArray(item.languages) && item.languages.length > 0) chips.push(item.languages.join('/'));
	if (Array.isArray(item.genres) && item.genres.length > 0) chips.push(item.genres.slice(0, 2).join(', '));
	if (chips.length === 0) return '';
	return `
		<div class="flex flex-wrap gap-2 text-xs text-slate-300">
			${chips
				.map((chip) => `<span class="rounded-full border border-slate-800/60 bg-slate-900/40 px-2 py-0.5">${escapeHtml(String(chip))}</span>`)
				.join('')}
		</div>
	`;
}

function openKrask2Options(index) {
	const item = state.krask2.items[index];
	if (!item) return;
	if (!item.external_id) {
		showToast('No download options available for this entry.', 'warning');
		return;
	}
	openKraskaOptionsModal({
		label: item.title,
		title: item.title,
		external_id: item.external_id,
		provider: 'krask2',
		contextType: 'krask2',
		sourceItem: item,
		catalog: summarizeKrask2Catalog(getSelectedKrask2Catalog()),
	});
}

function toggleKrask2Episodes(index) {
	const item = state.krask2.items[index];
	if (!item || item.type !== 'series') return;
	const metaState = getKrask2MetaState(item.id, true);
	if (!metaState) return;
	if (metaState.loading) return;
	if (Array.isArray(metaState.videos) && metaState.videos.length > 0) {
		metaState.expanded = !metaState.expanded;
		renderKrask2Episodes(index);
		return;
	}
	metaState.expanded = true;
	loadKrask2Episodes(item, index);
}

function getKrask2MetaState(itemId, create = false) {
	const key = typeof itemId === 'string' ? itemId : '';
	if (key === '') return null;
	if (!state.krask2.meta[key] && create) {
		state.krask2.meta[key] = { loading: false, error: null, videos: [], expanded: false, cache: null };
	}
	return state.krask2.meta[key] ?? null;
}

async function loadKrask2Episodes(item, itemIndex, options = {}) {
	if (!state.user) {
		showToast('Please sign in first.', 'warning');
		return;
	}
	const metaState = getKrask2MetaState(item.id, true);
	if (!metaState || metaState.loading) return;
	metaState.loading = true;
	metaState.error = null;
	metaState.cache = null;
	renderKrask2Episodes(itemIndex);
	const force = options.force === true;
	try {
		const { videos, cache } = await fetchKrask2EpisodesPayload(item, { force });
		metaState.videos = videos;
		metaState.cache = cache;
	} catch (error) {
		metaState.videos = [];
		metaState.error = messageFromError(error);
		metaState.cache = null;
	} finally {
		metaState.loading = false;
		state.krask2.meta[item.id] = metaState;
		renderKrask2Episodes(itemIndex);
	}
}

async function fetchKrask2EpisodesPayload(item, options = {}) {
	const params = new URLSearchParams({ type: item.type, id: item.id });
	if (options.force) {
		params.set('refresh', '1');
	}
	const response = await fetchJson(`${API.krask2Meta}?${params.toString()}`);
	const payload = response?.data?.meta ?? response?.data ?? {};
	const videos = Array.isArray(payload?.videos) ? payload.videos : [];
	return {
		videos: normalizeKrask2Episodes(videos),
		cache: normalizeCacheMeta(response?.cache ?? null),
	};
}

function normalizeKrask2Episodes(videos) {
	if (!Array.isArray(videos)) return [];
	return videos
		.map((video, index) => normalizeKrask2Episode(video, index))
		.filter(Boolean);
}

function normalizeKrask2Episode(video, index) {
	if (!video || typeof video !== 'object') return null;
	const id = typeof video.id === 'string' && video.id !== '' ? video.id : `episode-${index}`;
	const title = typeof video.name === 'string' && video.name.trim() !== ''
		? video.name.trim()
		: typeof video.title === 'string' && video.title.trim() !== ''
			? video.title.trim()
			: `Episode ${index + 1}`;
	const season = Number.parseInt(video.season ?? video.Season ?? '', 10);
	const episode = Number.parseInt(video.episode ?? video.Episode ?? '', 10);
	const externalId = typeof video.external_id === 'string' && video.external_id !== '' ? video.external_id : null;
	const description = typeof video.overview === 'string' && video.overview.trim() !== ''
		? video.overview.trim()
		: typeof video.description === 'string' && video.description.trim() !== ''
			? video.description.trim()
			: null;
	const languages = normalizeKrask2Languages(video.languages ?? video.language ?? video.lang ?? null);
	const seriesTitle = typeof video.series_title === 'string' && video.series_title.trim() !== ''
		? video.series_title.trim()
		: typeof video.seriesTitle === 'string' && video.seriesTitle.trim() !== ''
			? video.seriesTitle.trim()
			: typeof video.series === 'string' && video.series.trim() !== ''
				? video.series.trim()
				: null;
	const seriesId = typeof video.series_id === 'string' && video.series_id !== '' ? video.series_id : null;
	const episodeData = {
		id,
		title,
		type: 'episode',
		season: Number.isFinite(season) ? season : null,
		episode: Number.isFinite(episode) ? episode : null,
		description,
		languages,
		external_id: externalId,
		externalId,
		series_title: seriesTitle,
		series_id: seriesId,
		label: null,
	};
	episodeData.label = buildKrask2EpisodeLabel(episodeData);
	return episodeData;
}

function buildKrask2EpisodeLabel(episode) {
	const parts = [];
	if (Number.isFinite(episode.season) || Number.isFinite(episode.episode)) {
		const seasonLabel = Number.isFinite(episode.season) ? String(episode.season).padStart(2, '0') : '??';
		const episodeLabel = Number.isFinite(episode.episode) ? String(episode.episode).padStart(2, '0') : '??';
		parts.push(`S${seasonLabel}E${episodeLabel}`);
	}
	if (episode.title) {
		parts.push(episode.title);
	}
	return parts.length > 0 ? parts.join(' • ') : episode.title ?? 'Episode';
}

function renderKrask2Episodes(itemIndex) {
	if (!els.krask2List) return;
	const container = els.krask2List.querySelector(`[data-krask2-episodes="${itemIndex}"]`);
	if (!(container instanceof HTMLElement)) return;
	const item = state.krask2.items[itemIndex];
	if (!item || item.type !== 'series') {
		container.classList.add('hidden');
		container.innerHTML = '';
		return;
	}
	const metaState = getKrask2MetaState(item.id);
	if (!metaState || !metaState.expanded) {
		container.classList.add('hidden');
		container.innerHTML = '';
		return;
	}
	container.classList.remove('hidden');
	if (metaState.loading) {
		container.innerHTML = '<div class="text-sm text-brand-300">Loading episodes…</div>';
		return;
	}
	const header = buildKrask2EpisodesHeader(metaState, itemIndex);
	if (metaState.error) {
		container.innerHTML = `${header}<div class="rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200">${escapeHtml(metaState.error)}</div>`;
		return;
	}
	if (!Array.isArray(metaState.videos) || metaState.videos.length === 0) {
		container.innerHTML = `${header}<div class="text-sm text-slate-400">No episodes returned for this series.</div>`;
		return;
	}
	const grouped = groupKrask2Episodes(metaState.videos);
	const body = grouped
		.map((group) => renderKrask2SeasonBlock(group, itemIndex))
		.join('');
	container.innerHTML = `${header}${body}`;
}

function buildKrask2EpisodesHeader(metaState, itemIndex) {
	const label = buildCacheMetaText(metaState.cache ?? { hit: false }, 'Episodes cached', 'Episodes fetched live');
	const badge = label
		? `<span class="rounded-full border border-slate-800/60 bg-slate-900/40 px-2 py-0.5 text-[11px] uppercase tracking-wide text-slate-300">${escapeHtml(label)}</span>`
		: '';
	const disabled = metaState.loading ? 'disabled' : '';
	return `
		<div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs">
			<div class="text-slate-400">${badge}</div>
			<button type="button" data-krask2-episodes-refresh="${itemIndex}" class="rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-1 font-semibold text-slate-200 transition hover:border-brand-400/60 hover:text-brand-200 disabled:cursor-not-allowed disabled:opacity-50" ${disabled}>Refresh episodes</button>
		</div>
	`;
}

function renderKrask2EpisodeItem(video, itemIndex, episodeIndex) {
	const seriesItem = state.krask2.items[itemIndex];
	const description = video.description ? `<p class="text-xs text-slate-400">${escapeHtml(video.description)}</p>` : '';
	const languages = Array.isArray(video.languages) && video.languages.length > 0 ? `<span class="text-[11px] uppercase tracking-wide text-slate-400">${escapeHtml(video.languages.join(', '))}</span>` : '';
	const disabled = video.external_id ? '' : 'disabled';
	const label = video.label ?? buildKrask2EpisodeLabel(video);
	const key = seriesItem ? buildKrask2SelectionKey('episode', seriesItem.id, video.id) : '';
	const checked = key && isKrask2SelectionMarked(key) ? 'checked' : '';
	const selectionControl = video.external_id && key
		? `<input type="checkbox" data-krask2-episode-select="1" data-krask2-item-index="${itemIndex}" data-krask2-episode-index="${episodeIndex}" data-krask2-selection-key="${escapeHtml(key)}" class="h-4 w-4 rounded border-slate-600 bg-slate-800 text-brand-500 focus:ring-brand-500/60" ${checked} />`
		: '';
	return `
		<li class="rounded-lg border border-slate-800/50 bg-slate-950/40 p-3">
			<div class="flex flex-col gap-3">
				<div class="flex flex-wrap items-start gap-3">
					${selectionControl ? `<label class="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-300">${selectionControl}<span>Select</span></label>` : ''}
					<div>
						<p class="text-sm font-semibold text-slate-100">${escapeHtml(label)}</p>
						${languages}
						${description}
					</div>
				</div>
				<div class="flex flex-wrap items-center justify-between gap-3">
					<div class="text-[11px] text-slate-500">${video.season ? `S${String(video.season).padStart(2, '0')}` : ''}${video.episode ? `E${String(video.episode).padStart(2, '0')}` : ''}</div>
					<button type="button" data-krask2-episode-options="${itemIndex}:${episodeIndex}" class="rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:border-brand-400/60 hover:text-brand-200 disabled:cursor-not-allowed disabled:opacity-50" ${disabled}>${video.external_id ? 'Download options' : 'Unavailable'}</button>
				</div>
			</div>
		</li>
	`;
}

function groupKrask2Episodes(videos) {
	const buckets = new Map();
	videos.forEach((video, index) => {
		const rawSeason = Number.isFinite(video?.season) ? Number(video.season) : null;
		const key = rawSeason === null ? 'specials' : String(rawSeason);
		if (!buckets.has(key)) {
			buckets.set(key, []);
		}
		buckets.get(key).push({ video, index });
	});
	const sortedKeys = Array.from(buckets.keys()).sort((a, b) => {
		if (a === 'specials') return 1;
		if (b === 'specials') return -1;
		return Number(a) - Number(b);
	});
	return sortedKeys.map((key) => ({
		season: key === 'specials' ? null : Number(key),
		entries: buckets.get(key) ?? [],
	}));
}

function renderKrask2SeasonBlock(group, itemIndex) {
	const seriesItem = state.krask2.items[itemIndex];
	const seasonNumber = group.season;
	const label = formatKrask2SeasonLabel(seasonNumber);
	const key = seriesItem ? buildKrask2SelectionKey('season', seriesItem.id, seasonNumber) : '';
	const checked = key && isKrask2SelectionMarked(key) ? 'checked' : '';
	const entries = group.entries
		.map(({ video, index }) => renderKrask2EpisodeItem(video, itemIndex, index))
		.join('');
	const selectionControl = seriesItem && key
		? `<label class="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-300">
			<input type="checkbox" data-krask2-season-select="1" data-krask2-item-index="${itemIndex}" data-krask2-season-number="${seasonNumber === null ? 'null' : String(seasonNumber)}" data-krask2-selection-key="${escapeHtml(key)}" class="h-4 w-4 rounded border-slate-600 bg-slate-800 text-brand-500 focus:ring-brand-500/60" ${checked} />
			<span>Select season</span>
		</label>`
		: '';
	return `
		<div class="space-y-2 rounded-xl border border-slate-800/60 bg-slate-900/40 px-3 py-2">
			<div class="flex flex-wrap items-center justify-between gap-2">
				<p class="text-xs font-semibold uppercase tracking-wide text-slate-300">${escapeHtml(label)}</p>
				${selectionControl}
			</div>
			<ul class="space-y-2">
				${entries}
			</ul>
		</div>
	`;
}

function formatKrask2SeasonLabel(seasonNumber) {
	if (seasonNumber === null) {
		return 'Specials';
	}
	const padded = String(seasonNumber).padStart(2, '0');
	return `Season ${padded}`;
}

function handleKrask2SelectionChange(event) {
	const target = event.target;
	if (!(target instanceof HTMLInputElement)) return;
	if (target.dataset.krask2ItemSelect === '1') {
		const kind = target.dataset.krask2SelectKind ?? '';
		const key = target.dataset.krask2SelectionKey ?? '';
		const index = Number.parseInt(target.dataset.krask2ItemIndex ?? '', 10);
		toggleKrask2ItemSelection(kind, index, key, Boolean(target.checked), target);
		return;
	}
	if (target.dataset.krask2EpisodeSelect === '1') {
		const key = target.dataset.krask2SelectionKey ?? '';
		const itemIndex = Number.parseInt(target.dataset.krask2ItemIndex ?? '', 10);
		const episodeIndex = Number.parseInt(target.dataset.krask2EpisodeIndex ?? '', 10);
		toggleKrask2EpisodeSelection(itemIndex, episodeIndex, key, Boolean(target.checked), target);
		return;
	}
	if (target.dataset.krask2SeasonSelect === '1') {
		const key = target.dataset.krask2SelectionKey ?? '';
		const itemIndex = Number.parseInt(target.dataset.krask2ItemIndex ?? '', 10);
		const rawSeason = target.dataset.krask2SeasonNumber ?? '';
		const seasonNumber = rawSeason === 'null' || rawSeason === '' ? null : Number.parseInt(rawSeason, 10);
		toggleKrask2SeasonSelection(itemIndex, seasonNumber, key, Boolean(target.checked), target);
	}
}

function toggleKrask2ItemSelection(kind, itemIndex, key, checked, target) {
	if (!key) return;
	const item = state.krask2.items[itemIndex];
	if (!item) {
		target.checked = false;
		return;
	}
	if (checked) {
		let selection = null;
		if (kind === 'series') {
			selection = makeKrask2SeriesSelection(item);
		} else if (kind === 'movie') {
			selection = makeKrask2MovieSelection(item);
		}
		if (!selection) {
			target.checked = false;
			showToast('Unable to select this entry.', 'warning');
			return;
		}
		state.krask2.selected.set(key, selection);
	} else {
		state.krask2.selected.delete(key);
	}
	updateKrask2QueueButton();
}

function toggleKrask2EpisodeSelection(itemIndex, episodeIndex, key, checked, target) {
	if (!key) return;
	const seriesItem = state.krask2.items[itemIndex];
	if (!seriesItem) {
		target.checked = false;
		return;
	}
	const metaState = getKrask2MetaState(seriesItem.id);
	const episode = metaState?.videos?.[episodeIndex];
	if (!episode) {
		target.checked = false;
		return;
	}
	if (checked) {
		const selection = makeKrask2EpisodeSelection(seriesItem, episode);
		if (!selection) {
			target.checked = false;
			showToast('Episode is missing download metadata.', 'warning');
			return;
		}
		state.krask2.selected.set(key, selection);
	} else {
		state.krask2.selected.delete(key);
	}
	updateKrask2QueueButton();
}

function toggleKrask2SeasonSelection(itemIndex, seasonNumber, key, checked, target) {
	if (!key) return;
	const seriesItem = state.krask2.items[itemIndex];
	if (!seriesItem) {
		target.checked = false;
		return;
	}
	if (checked) {
		const selection = makeKrask2SeasonSelection(seriesItem, seasonNumber);
		if (!selection) {
			target.checked = false;
			showToast('Unable to select this season.', 'warning');
			return;
		}
		state.krask2.selected.set(key, selection);
	} else {
		state.krask2.selected.delete(key);
	}
	updateKrask2QueueButton();
}

function makeKrask2MovieSelection(item) {
	const key = buildKrask2SelectionKey('movie', item?.id ?? '', null);
	if (!key || !item?.external_id) {
		return null;
	}
	return {
		key,
		kind: 'movie',
		item,
		catalog: getActiveKrask2CatalogSummary(),
	};
}

function makeKrask2SeriesSelection(item) {
	const key = buildKrask2SelectionKey('series', item?.id ?? '', null);
	if (!key) {
		return null;
	}
	return {
		key,
		kind: 'series',
		item,
		catalog: getActiveKrask2CatalogSummary(),
		seasonNumber: null,
	};
}

function makeKrask2SeasonSelection(seriesItem, seasonNumber) {
	const key = buildKrask2SelectionKey('season', seriesItem?.id ?? '', seasonNumber);
	if (!key) {
		return null;
	}
	return {
		key,
		kind: 'season',
		seriesItem,
		seasonNumber,
		catalog: getActiveKrask2CatalogSummary(),
	};
}

function makeKrask2EpisodeSelection(seriesItem, episode) {
	const key = buildKrask2SelectionKey('episode', seriesItem?.id ?? '', episode?.id ?? '');
	if (!key || !episode?.external_id) {
		return null;
	}
	return {
		key,
		kind: 'episode',
		seriesItem,
		episode,
		catalog: getActiveKrask2CatalogSummary(),
	};
}

function getActiveKrask2CatalogSummary() {
	return summarizeKrask2Catalog(getSelectedKrask2Catalog());
}

function buildKrask2SelectionKey(kind, subjectId, extra) {
	const id = typeof subjectId === 'string' && subjectId !== '' ? subjectId : '';
	if (id === '') {
		return '';
	}
	switch (kind) {
		case 'movie':
			return `krask2:movie:${id}`;
		case 'series':
			return `krask2:series:${id}`;
		case 'season': {
			const seasonPart = extra === null || extra === undefined ? 'specials' : String(extra);
			return `krask2:season:${id}:${seasonPart}`;
		}
		case 'episode': {
			const episodeId = typeof extra === 'string' && extra !== '' ? extra : String(extra ?? '');
			if (episodeId === '') {
				return '';
			}
			return `krask2:episode:${id}:${episodeId}`;
		}
		default:
			return '';
	}
}

function isKrask2SelectionMarked(key) {
	return Boolean(key && state.krask2.selected?.has(key));
}

function setKrask2QueueLabel(text) {
	state.krask2.queueLabel = text;
	updateKrask2QueueButton();
}

function estimateKrask2SelectionSize() {
	let total = 0;
	state.krask2.selected?.forEach((entry) => {
		if ((entry.kind === 'series' || entry.kind === 'season') && Number.isFinite(entry.episodeCount)) {
			total += Number(entry.episodeCount);
		} else {
			total++;
		}
	});
	return total;
}

function updateKrask2QueueButton() {
	if (!els.krask2QueueBtn) return;
	const selectionCount = state.krask2.selected?.size ?? 0;
	const labelOverride = state.krask2.queueLabel ?? '';
	if (selectionCount === 0 && labelOverride === '') {
		toggleElement(els.krask2QueueBtn, false);
		return;
	}
	toggleElement(els.krask2QueueBtn, true, 'inline-flex');
	if (labelOverride) {
		els.krask2QueueBtn.textContent = labelOverride;
	} else {
		const estimate = estimateKrask2SelectionSize();
		const countLabel = estimate > 0 ? estimate : selectionCount;
		const plural = countLabel === 1 ? '' : 's';
		els.krask2QueueBtn.textContent = `Add ${countLabel} item${plural} to queue`;
	}
	if (state.isQueueSubmitting) {
		els.krask2QueueBtn.setAttribute('disabled', 'true');
	} else {
		els.krask2QueueBtn.removeAttribute('disabled');
	}
}

async function queueSelectedKrask2() {
	if (!state.user) {
		showToast('Please sign in first.', 'warning');
		return;
	}
	if (state.isQueueSubmitting || (state.krask2.selected?.size ?? 0) === 0) {
		return;
	}
	state.isQueueSubmitting = true;
	setKrask2QueueLabel('Preparing selection…');
	const selections = Array.from(state.krask2.selected.values());
	const candidates = [];
	const dedupe = new Set();

	try {
		let processed = 0;
		for (const entry of selections) {
			processed++;
			setKrask2QueueLabel(`Preparing ${processed}/${selections.length}…`);
			try {
				const expanded = await expandKrask2SelectionEntry(entry);
				expanded.forEach((candidate) => {
					const uniqueKey = candidate.uniqueKey ?? candidate.externalId;
					if (!uniqueKey || dedupe.has(uniqueKey)) {
						return;
					}
					dedupe.add(uniqueKey);
					candidates.push(candidate);
				});
			} catch (error) {
				console.warn('Failed to expand KraSk2 selection', error);
			}
		}

		if (candidates.length === 0) {
			showToast('Nothing to queue.', 'warning');
			return;
		}

		const payloadItems = [];
		let prepared = 0;
		for (const candidate of candidates) {
			prepared++;
			setKrask2QueueLabel(`Handing off ${prepared}/${candidates.length}…`);
			if (!candidate.externalId) {
				continue;
			}
			const context = buildKrask2DownloadContext({
				sourceItem: candidate.sourceItem,
				parentItem: candidate.parentItem ?? null,
				catalog: candidate.catalog ?? null,
				label: candidate.label,
				externalId: candidate.externalId,
			});
			const metadata = buildKrask2JobMetadata(context, null);
			const itemPayload = {
				provider: 'krask2',
				external_id: candidate.externalId,
				title: context.label ?? candidate.label ?? 'KraSk2 item',
			};
			if (metadata) {
				itemPayload.metadata = metadata;
			}
			payloadItems.push(itemPayload);
		}

		if (payloadItems.length === 0) {
			showToast('Unable to prepare the selected entries.', 'error');
			return;
		}

		setKrask2QueueLabel('Submitting to server…');
		await fetchJson(API.krask2BulkQueue, {
			method: 'POST',
			body: JSON.stringify({ items: payloadItems }),
		});
		showToast(`Background resolver accepted ${payloadItems.length} item${payloadItems.length === 1 ? '' : 's'}.`, 'success');
		state.krask2.selected = new Map();
		state.krask2.queueLabel = '';
		renderKrask2View();
		if (state.currentView === 'queue') {
			loadJobs();
		}
	} catch (error) {
		showToast(messageFromError(error), 'error');
	} finally {
		state.isQueueSubmitting = false;
		state.krask2.queueLabel = '';
		updateKrask2QueueButton();
	}
}

async function expandKrask2SelectionEntry(entry) {
	if (!entry || typeof entry !== 'object') {
		return [];
	}
	const catalog = entry.catalog ?? getActiveKrask2CatalogSummary();
	if (entry.kind === 'movie' && entry.item) {
		return [buildKrask2CandidateFromItem(entry.item, catalog)];
	}
	if (entry.kind === 'episode' && entry.episode && entry.seriesItem) {
		return [buildKrask2CandidateFromEpisode(entry.episode, entry.seriesItem, catalog)];
	}
	if (entry.kind === 'series' && entry.item) {
		const episodes = await fetchAllEpisodesForSeries(entry.item);
		entry.episodeCount = episodes.length;
		return episodes.map((episode) => buildKrask2CandidateFromEpisode(episode, entry.item, catalog));
	}
	if (entry.kind === 'season' && entry.seriesItem) {
		const episodes = await fetchAllEpisodesForSeries(entry.seriesItem);
		const filtered = episodes.filter((episode) => {
			const season = Number.isFinite(episode?.season) ? Number(episode.season) : null;
			return season === (entry.seasonNumber ?? null);
		});
		entry.episodeCount = filtered.length;
		return filtered.map((episode) => buildKrask2CandidateFromEpisode(episode, entry.seriesItem, catalog));
	}
	return [];
}

async function fetchAllEpisodesForSeries(seriesItem) {
	const metaState = getKrask2MetaState(seriesItem.id, true);
	if (metaState && Array.isArray(metaState.videos) && metaState.videos.length > 0) {
		return metaState.videos;
	}
	const { videos } = await fetchKrask2EpisodesPayload(seriesItem, { force: false });
	if (metaState) {
		metaState.videos = videos;
		state.krask2.meta[seriesItem.id] = metaState;
	}
	return videos;
}

function buildKrask2CandidateFromItem(item, catalog) {
	return {
		uniqueKey: `krask2:movie:${item.id}`,
		externalId: item.external_id ?? item.externalId ?? null,
		label: item.title ?? item.label ?? 'Kra.sk item',
		sourceItem: item,
		parentItem: null,
		catalog,
	};
}

function buildKrask2CandidateFromEpisode(episode, seriesItem, catalog) {
	return {
		uniqueKey: `krask2:episode:${seriesItem.id}:${episode.id}`,
		externalId: episode.external_id ?? episode.externalId ?? null,
		label: episode.label ?? buildKrask2EpisodeLabel(episode),
		sourceItem: episode,
		parentItem: seriesItem,
		catalog,
	};
}

function buildKrask2DownloadContext({ sourceItem, parentItem = null, catalog, label, externalId }) {
	const resolvedLabel = label ?? sourceItem?.title ?? sourceItem?.label ?? 'Kra.sk item';
	return {
		label: resolvedLabel,
		title: resolvedLabel,
		externalId: externalId ?? sourceItem?.external_id ?? sourceItem?.externalId ?? null,
		contextType: 'krask2',
		provider: 'krask2',
		sourceItem,
		sourceParent: parentItem,
		catalog,
	};
}

async function fetchKrask2Variants(externalId) {
	const params = new URLSearchParams({ external_id: externalId });
	const response = await fetchJson(`${API.krask2Options}?${params.toString()}`);
	return normalizeKraskaVariants(response?.data ?? []);
}

function pickPreferredKrask2Variant(variants) {
	if (!Array.isArray(variants) || variants.length === 0) {
		return null;
	}
	const sorted = [...variants].sort((a, b) => {
		const sizeA = Number(a?.size_bytes ?? 0);
		const sizeB = Number(b?.size_bytes ?? 0);
		if (sizeA !== sizeB) {
			return sizeB - sizeA;
		}
		const bitrateA = Number(a?.bitrate_kbps ?? 0);
		const bitrateB = Number(b?.bitrate_kbps ?? 0);
		if (bitrateA !== bitrateB) {
			return bitrateB - bitrateA;
		}
		return 0;
	});
	return sorted[0];
}

function buildQueuePayloadFromVariant(context, variant) {
	const provider = context?.provider ?? 'kraska';
	const targetIdent = typeof variant?.kra_ident === 'string' && variant.kra_ident !== ''
		? variant.kra_ident
		: typeof variant?.id === 'string' && variant.id !== ''
			? variant.id
			: context?.externalId ?? null;
	if (!targetIdent) {
		throw new Error('Missing download identifier for selected option.');
	}
	const titleParts = [context?.label ?? context?.title ?? 'Kra.sk item'];
	if (variant?.title) {
		titleParts.push(variant.title);
	} else if (variant?.quality) {
		titleParts.push(variant.quality);
	}
	const payload = {
		provider,
		external_id: targetIdent,
		title: titleParts.filter(Boolean).join(' • '),
	};
	if (provider === 'krask2') {
		const metadata = buildKrask2JobMetadata(context, variant);
		if (metadata) {
			payload.metadata = metadata;
		}
	}
	return payload;
}

function openKrask2EpisodeOptions(itemIndex, episodeIndex) {
	const item = state.krask2.items[itemIndex];
	if (!item) return;
	const metaState = getKrask2MetaState(item.id);
	const episode = metaState?.videos?.[episodeIndex];
	if (!episode || !episode.external_id) {
		showToast('Episode is missing download options.', 'warning');
		return;
	}
	const label = episode.label ?? `${item.title} • ${buildKrask2EpisodeLabel(episode)}`;
	openKraskaOptionsModal({
		label,
		title: label,
		external_id: episode.external_id,
		provider: 'krask2',
		contextType: 'krask2',
		sourceItem: episode,
		sourceParent: item,
		catalog: summarizeKrask2Catalog(getSelectedKrask2Catalog()),
	});
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
		state.providersLoaded = true;
		renderProviders();
		renderProviderFilters();
	} catch (error) {
		state.providers = defaultProviders();
		state.providersLoaded = true;
		renderProvidersError(error);
		renderProviderFilters();
	} finally {
		updateKraskaProviderToggle();
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
	const paused = provider.paused === true;
	const pauseInfo = provider.pause ?? null;
	const statusLabel = paused ? 'Paused' : provider.enabled ? 'Enabled' : 'Disabled';
	const statusTextClass = paused ? 'text-amber-300' : provider.enabled ? 'text-emerald-300' : 'text-rose-300';
	const statusDotClass = paused ? 'bg-amber-400' : provider.enabled ? 'bg-emerald-400' : 'bg-rose-400';
	const pauseMetaParts = [];
	if (pauseInfo && pauseInfo.paused_at) {
		const since = formatRelativeTime(pauseInfo.paused_at);
		if (since) pauseMetaParts.push(`since ${escapeHtml(since)}`);
	}
	if (pauseInfo && (pauseInfo.paused_by || pauseInfo.paused_by_name)) {
		const by = pauseInfo.paused_by ?? pauseInfo.paused_by_name;
		if (by) pauseMetaParts.push(`by ${escapeHtml(String(by))}`);
	}
	const pauseNote = pauseInfo && pauseInfo.note ? escapeHtml(String(pauseInfo.note)) : null;
	const pauseDetails = paused
		? `<div class="mt-3 rounded-lg border border-amber-500/40 bg-amber-500/15 p-3 text-xs text-amber-100">Jobs for this provider remain queued until resumed.${pauseMetaParts.length > 0 ? ` <span class="block mt-1 opacity-80">${pauseMetaParts.join(' · ')}</span>` : ''}${pauseNote ? ` <span class="block mt-1 opacity-80">Note: ${pauseNote}</span>` : ''}</div>`
		: '';

	const actionButtons = [];
	actionButtons.push('<button type="button" data-provider-test class="rounded-lg border border-slate-700/60 bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:border-brand-400/60 hover:text-brand-200">Test</button>');
	actionButtons.push('<button type="button" data-provider-edit class="rounded-lg border border-brand-500/40 bg-brand-500/20 px-3 py-1.5 text-xs font-semibold text-brand-100 transition hover:bg-brand-500/30">Edit</button>');
	actionButtons.push('<button type="button" data-provider-delete class="rounded-lg border border-rose-500/40 bg-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-100 transition hover:bg-rose-500/30">Delete</button>');
	if (paused) {
		actionButtons.unshift('<button type="button" data-provider-resume class="rounded-lg border border-emerald-500/40 bg-emerald-500/20 px-3 py-1.5 text-xs font-semibold text-emerald-100 transition hover:bg-emerald-500/30">Resume</button>');
	} else {
		actionButtons.unshift('<button type="button" data-provider-pause class="rounded-lg border border-amber-500/40 bg-amber-500/20 px-3 py-1.5 text-xs font-semibold text-amber-100 transition hover:bg-amber-500/30">Pause</button>');
	}

	return `
		<li data-provider-id="${provider.id}" class="rounded-2xl border border-slate-800/70 bg-slate-900/50 p-5">
			<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
				<div>
					<h4 class="text-lg font-semibold text-slate-100">${escapeHtml(provider.name ?? provider.key)}</h4>
					<div class="text-sm text-slate-400">Key: ${escapeHtml(provider.key)}</div>
					<div class="mt-2 flex items-center gap-2 text-xs uppercase tracking-wide ${statusTextClass}">
						<span class="inline-flex h-2 w-2 rounded-full ${statusDotClass}"></span>
						${statusLabel}
					</div>
				</div>
				<div class="flex flex-wrap gap-2">${actionButtons.join('')}</div>
			</div>
			<dl class="mt-4 grid gap-2 text-xs text-slate-400">
				<div class="flex justify-between"><span>Updated</span><span>${formatRelativeTime(provider.updated_at)}</span></div>
				<div class="flex justify-between"><span>Created</span><span>${formatRelativeTime(provider.created_at)}</span></div>
			</dl>
			${pauseDetails}
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
			<td class="px-3 py-2 text-[11px] text-slate-400"><span title="${escapeHtml(absolute)}">${escapeHtml(relative)}</span></td>
			<td class="px-3 py-2 text-[11px] font-mono text-slate-300">${escapeHtml(createdAt)}</td>
			<td class="px-3 py-2 text-xs text-slate-200">
				<div class="flex flex-col">
					<span class="font-medium">${escapeHtml(userName)}</span>
					${user.email && user.email !== userName ? `<span class="text-[10px] text-slate-500">${escapeHtml(user.email)}</span>` : ''}
				</div>
			</td>
			<td class="px-3 py-2 text-xs text-slate-100">${escapeHtml(entry.action ?? '')}</td>
			<td class="px-3 py-2 text-xs text-slate-300">${escapeHtml(subjectLabel)}</td>
			<td class="px-3 py-2">${payloadDisplay}</td>
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
		if (state.currentView === 'queue') {
			loadJobs();
		}
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

async function pauseProvider(providerId) {
	const provider = state.providers.find((p) => Number(p.id) === Number(providerId));
	const existingNote = provider?.pause?.note ?? '';
	const noteInput = window.prompt('Optional note for pausing this provider:', existingNote || '');
	if (noteInput === null) return;
	const payload = { id: providerId };
	const trimmedNote = noteInput.trim();
	if (trimmedNote !== '') {
		payload.note = trimmedNote;
	}
	try {
		await fetchJson(API.providerPause, {
			method: 'POST',
			body: JSON.stringify(payload),
		});
		showToast('Provider paused.', 'warning');
		await loadProviders();
		if (state.currentView === 'queue') {
			loadJobs().catch(() => {});
		}
	} catch (error) {
		showToast(`Failed to pause provider: ${messageFromError(error)}`, 'error');
	}
}

async function resumeProvider(providerId) {
	try {
		await fetchJson(API.providerResume, {
			method: 'POST',
			body: JSON.stringify({ id: providerId }),
		});
		showToast('Provider resumed.', 'success');
		await loadProviders();
		if (state.currentView === 'queue') {
			loadJobs().catch(() => {});
		}
	} catch (error) {
		showToast(`Failed to resume provider: ${messageFromError(error)}`, 'error');
	}
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
