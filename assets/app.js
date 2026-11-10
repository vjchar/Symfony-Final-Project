import './stimulus_bootstrap.js';
// Loaded on every page, not only lazily where a Symfony form is rendered: the
// hand-written sign-in and sign-out forms use stateless CSRF tokens too, and once
// a session has used the double-submit cookie the server insists on it.
import './controllers/csrf_protection_controller.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 *
 * The VJ Car Rental v15 behaviour (menus, form validation, carousels) lives in
 * assets/js/*.js and is loaded as classic scripts by base.html.twig.
 */
