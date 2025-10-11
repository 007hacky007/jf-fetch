export function injectUtilityStyles() {
	const style = document.createElement('style');
	style.textContent = `
		.job-btn {
			border-radius: 9999px;
			border: 1px solid rgba(148, 163, 184, 0.4);
			background: rgba(15, 23, 42, 0.6);
			padding: 0.35rem 0.9rem;
			font-size: 0.75rem;
			font-weight: 600;
			color: rgba(226, 232, 240, 0.85);
			transition: all 0.2s ease;
		}
		.job-btn:hover,
		.job-btn:focus-visible {
			border-color: rgba(99, 102, 241, 0.6);
			color: #fff;
			outline: none;
		}
		.job-btn-danger {
			border-color: rgba(248, 113, 113, 0.6);
			color: rgba(248, 113, 113, 0.95);
		}
		.job-btn-danger:hover,
		.job-btn-danger:focus-visible {
			background: rgba(248, 113, 113, 0.15);
			color: #fff;
		}
	`;
	document.head.appendChild(style);
}
