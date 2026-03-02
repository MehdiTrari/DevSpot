import './stimulus_bootstrap.js';
import { Turbo } from '@hotwired/turbo';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

if (!window.__devspotTurboStarted) {
	Turbo.start();
	Turbo.cache.exemptPageFromPreview();
	window.__devspotTurboStarted = true;
}

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
