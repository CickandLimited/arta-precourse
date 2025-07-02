# Ayotte Precourse Portal

Ayotte Precourse Portal is a WordPress plugin that manages an invitation based workflow for students before they begin a course. Administrators can send invites, assign forms, and monitor completion. Students register through a unique token and complete their assigned forms from a simple dashboard.

## Setup

1. Copy this plugin's folder into `wp-content/plugins/` on your WordPress site and activate **Ayotte Precourse Portal**.
2. Activation automatically creates the `Precourse Forms` dashboard page (accessible at `/precourse-forms`). Any old `Precourse Form` page is removed.
3. A new **Precourse Portal** menu will appear in the WordPress admin with tools for invites, debugging, progress tracking and form management.
4. Configure an external forms database from **Precourse Portal → Form DB Settings** if you plan to use the custom form manager.

## Basic Usage

- Use the **Precourse Portal** admin page to send invitations. Each address receives credentials and a link to complete registration.
- Assign available forms to students from **Student Progress**. Progress percentages update automatically when forms are submitted.
- Manage and preview forms under **Custom Forms**. Preview opens a live rendering of the form inside the admin area.
- Students visit the `Precourse Forms` page (`/precourse-forms`) to access their dashboard and fill out each assigned form.
- Logs are viewable from the **Debug Console** submenu.

## Custom Form Manager

Forms created through **Custom Forms** are stored in a separate database. You can build fields, preview the result and then assign the form to students. Use the `[ayotte_custom_form]` shortcode anywhere you need to embed a form:

```
[ayotte_custom_form id="1"]
```

The preview link in the Custom Forms list opens the form inside the admin area using the same shortcode.

Checkbox fields include a **Minimum checked** setting to require a certain number of options be selected.

## Shortcodes

- ``[ayotte_precourse_form]`` – Displays the built‑in precourse form. Provide `id` to display a custom form by ID, e.g. ``[ayotte_precourse_form id="123"]``.
- ``[ayotte_form_dashboard]`` – Shows the logged‑in user's list of assigned forms and their status.
- ``[ayotte_custom_form id="1"]`` – Display a custom form stored in the external database.

