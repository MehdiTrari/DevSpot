import { startStimulusApp } from '@symfony/stimulus-bundle';
import MatchingPreviewController from './controllers/matching_preview_controller.js';
import RecruiterOfferMatchingController from './controllers/recruiter_offer_matching_controller.js';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
app.register('matching-preview', MatchingPreviewController);
app.register('recruiter-offer-matching', RecruiterOfferMatchingController);
