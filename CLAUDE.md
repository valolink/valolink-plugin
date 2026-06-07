# CLAUDE.md - Many-in-One Performance WP Plugin

## 1. Project Context

- **Goal:** Develop a custom, highly performant "many-in-one" WordPress plugin that provides utility, security, and management features for client sites while keeping the WordPress and WooCommerce footprints strictly lean.
- **Architecture Design:** A strictly modular system. The core framework handles only module registration, settings management, and conditional loading. All features are isolated into independent modules.
- **Licensing & Distribution:** The plugin is intended to be open-source. Because the source code will be publicly visible, all security boundaries must rely on robust cryptographic architectures rather than obscurity.

## 2. Core Principles & Constraints

- **Zero-Impact Deactivation:** If a module is toggled off, it must have absolutely zero footprint. No background processing, no asset loading, and no hidden hooks.
- **Context-Aware Loading:** The logic determining when a module loads must be highly comprehensive and strictly defined. Modules must only load if they are highly relevant to the specific context of the current request (e.g., skipping irrelevant code during specific AJAX calls).
- **Graceful Failure:** The plugin must fail without causing critical errors. Extensive checks must be in place before operations run to avoid bringing down the site.
- **Environment Compatibility:** The plugin must verify environment compatibility (e.g., PHP and WP versions) before executing. If incompatible, it must display a safe notification rather than breaking the site.
- **Strict Security:** Aggressive sanitization of inputs, late escaping of outputs, and strict permission validations are required across all features and endpoints.
- **Database Hygiene & Clean Uninstall:** Avoid unnecessary database bloat and autoloaded data. The plugin must leave absolutely no residual data, tables, or settings behind when uninstalled.
- **Asset Loading Discipline:** Any frontend assets (CSS/JS) must strictly load only on the exact pages or contexts where the active module requires them.
- **Caching Compatibility:** The plugin must use caching for heavy operations and remain entirely compatible with standard WordPress page caching and object caching mechanisms.
- **Conflict Prevention:** All code must be strictly encapsulated to prevent naming collisions with other themes or plugins.
- **Native Update Integration:** The plugin must integrate seamlessly with the native WordPress Core update system, allowing administrators to see update notifications and trigger updates directly from the standard WordPress Plugins list.

## 3. Module Requirements

### Module A: Security

- **Objective:** Harden site security without performance bloat.
- **Requirements:**
  - Implement robust login protection.
  - File monitoring and malware scanning must be scheduled and performant, never running on or impacting user-facing requests.
  - IP blocking and logging mechanisms must be highly optimized to prevent database locking during high-traffic events.
  - **Vulnerability Alerts:** Implement a lightweight system to detect and alert administrators to known security vulnerabilities in active plugins, themes, or WordPress core without adding runtime performance overhead.

### Module B: Staging Detection & Helpers

- **Objective:** Prevent staging/local environments from interfering with live production.
- **Requirements:**
  - Accurately and reliably detect staging or local environments.
  - Upon detection, automatically implement safeguards: block search engine indexing, prevent outgoing client emails, and safely disable live payment gateways.

### Module C: Remote Dashboard Connection (Open-Source Security Compliant)

- **Objective:** Centralized management from the main agency dashboard via secure remote execution.
- **Requirements:**
  - Expose authenticated remote endpoints that integrate seamlessly with the native WordPress update and management mechanisms.
  - Allow the remote dashboard to toggle modules on/off, fetch site health/status, and safely trigger plugin updates.
  - API responses must be cached where appropriate and highly performant.
  - **Public Code Security Boundary:** Because the source code is public, the endpoint authentication mechanism must strictly prevent replay attacks, unauthorized access, and credential harvesting. It must rely entirely on robust server-to-server secret handshakes or cryptographic signing so that knowing _how_ the endpoint works grants zero advantage to attackers.

### Module D: Logging

- **Objective:** Comprehensive event tracking without degrading the database.
- **Requirements:**
  - Implement a logging storage system that does not bloat the primary WordPress tables.
  - Include automated log rotation and cleanup.
  - The admin interface for viewing logs must load asynchronously to prevent backend slowdowns.

### Module E: Agency Branding & Support

- **Objective:** White-label the WordPress login experience and provide direct client support access.
- **Requirements:**
  - Replace the default WordPress logo on the login screen with the agency logo.
  - Inject agency customer service contact information clearly beneath the login form.
  - Ensure branding modifications do not interfere with third-party login security measures.

### Module F: Advanced Performance Optimizations

- **Objective:** Provide surgical performance enhancements that complement standard caching tools (e.g., WP Rocket).
- **Requirements:**
  - Implement logic to selectively disable heavy or unnecessary plugins during specific AJAX requests to reduce server response times.
  - Include other low-level optimization toggles for bottlenecks not covered by traditional caching solutions.
  - Ensure these optimizations do not break core WordPress or WooCommerce frontend functionality.

### Module G: Site Health & Best Practice Auditor

- **Objective:** Ensure the site and its core utilities do not rely on default, insecure, or sub-optimal configurations.
- **Requirements:**
  - Audit standard WordPress core settings and identify elements still utilizing risky defaults (e.g., default permalinks, exposed user registration options, or default administrator usernames).
  - Extend audits to third-party companion plugins (e.g., verifying if WP Rocket settings align with agency performance benchmarks).
  - Report sub-optimal configurations to site administrators via a lean backend summary interface without running continuous database or runtime checks.

### Module H: Agency Curated Plugin Installer

- **Objective:** Streamline new site deployment by providing rapid access to the agency's verified plugin ecosystem.
- **Requirements:**
  - Maintain a centrally managed list of recommended/trusted plugins directly within the administration interface.
  - Allow administrators to easily trigger the installation and activation of these trusted tools directly from the plugin UI.
  - Ensure this module's data footprint is lightweight, loading remote asset descriptions or lists only on-demand when the administrator views the installer interface.

### Module I: Experimental Plugin Dependency Analyzer

- **Objective:** Explore code analysis to intelligently recommend conditional plugin disabling.
- **Requirements:**
  - Implement a static analysis utility (e.g., basic AST or regex parsing) that scans a chosen third-party plugin's codebase for specific WordPress hook registrations, AJAX handlers, and enqueued assets.
  - Generate an "Impact Map" or "Confidence Score" to inform the site administrator how deeply integrated the plugin is, helping them decide if it is safe to conditionally disable it on specific frontend or AJAX requests.
  - Emphasize safety: The tool must serve as an advisory mechanism, leaving the final toggle decision to the administrator to prevent automated site breakage.

### Module J: JavaScript Interaction Loader

- **Objective:** Improve initial page load times and Core Web Vitals by delaying non-essential JavaScript until user interaction.
- **Requirements:**
  - Implement a mechanism to safely defer or delay the execution of targeted JavaScript snippets/files until a user interacts with the page (e.g., scroll, click, or mouse movement).
  - Provide a clear UI for administrators to define which scripts should be delayed and establish a strict exclusion list for critical scripts.
  - Include a fallback timeout to load delayed scripts automatically if no user interaction occurs after a set duration.
  - Ensure compatibility with vital dynamic flows, such as WooCommerce cart updates and checkout processing, so core functionalities are never broken.

### Module K: Isolated Shortcode Lazy Loader

- **Objective:** Act as a last-resort optimization for exceptionally heavy plugins by quarantining their output into an asynchronous, lazy-loaded iframe.
- **Requirements:**
  - Register a hidden Custom Post Type (CPT) designated strictly for hosting isolated shortcodes or heavy plugin outputs.
  - Force a completely stripped-down, minimal page template for this CPT that bypasses the active theme's header/footer and only loads the bare minimum WordPress core necessary to execute the target plugin.
  - Provide a lightweight wrapper shortcode to embed this CPT on standard pages via a `loading="lazy"` iframe.
  - Implement parent-child JavaScript communication (e.g., via `window.postMessage`) to automatically adjust the iframe height to match its internal dynamic content, ensuring a seamless visual experience without internal scrollbars.
