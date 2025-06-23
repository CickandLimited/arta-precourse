# Ayotte Precourse Portal

Ayotte Precourse Portal is a WordPress plugin that manages an invitation based workflow for students before they begin a course. Administrators can send invites, assign Forminator forms, and monitor completion. Students register through a unique token and complete their assigned forms from a simple dashboard.

## Setup

1. Install and activate the **Forminator** plugin.
2. Copy this plugin's folder into `wp-content/plugins/` on your WordPress site and activate **Ayotte Precourse Portal**.
3. Activation automatically creates the pages `Precourse Forms` (dashboard) and `Precourse Form` (form display).
4. A new **Precourse Portal** menu will appear in the WordPress admin with tools for invites, debugging, progress tracking and form management.

## Basic Usage

- Use the **Precourse Portal** admin page to send invitations. Each address receives credentials and a link to complete registration.
- Assign available forms to students from **Student Progress**. Progress percentages update automatically when forms are submitted.
- The **Form Sets** page lets you choose which Forminator forms are available for assignment.
- Students visit the `Precourse Forms` page to access their dashboard and fill out each assigned form.
- Logs are viewable from the **Debug Console** submenu.

## Forminator Tracking

Form submissions are tracked through Forminator. To enable tracking:

1. Ensure Forminator is installed and activated.
2. Visit **Precourse Portal → Form Sets** and select the Forminator forms that should be tracked.
3. On the **Student Progress** page assign these forms to individual students. When a tracked form is submitted, the plugin marks it complete and recalculates the student's progress.

## Shortcodes

- ``[ayotte_precourse_form]`` – Displays the built‑in precourse form. Provide `id` to display a specific Forminator form, e.g. ``[ayotte_precourse_form id="123"]``.
- ``[ayotte_form_dashboard]`` – Shows the logged‑in user's list of assigned forms and their status.

