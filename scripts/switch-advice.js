#!/usr/bin/env node
/**
 * switch-advice.js: what to tell the user when a route of the divi5-builder plugin is missing (issue #37).
 *
 * Media audit, media clean-up and updates each sit behind a switch in Settings > Divi 5 Builder, OFF by default;
 * while a switch is off its REST routes do not exist (404 rest_no_route). The scripts never switch anything on
 * themselves (an administrator does that in wp-admin): they only say which switch to flip.
 *
 *   adviceFor(part, status)   part = page_building | media_audit | media_clean_up | updates
 *                             status = the parsed GET divi5-builder/v1/status, or null (no combined plugin)
 *   adviceForSite(site, part) asks `wp.js <site> status` (read-only), then adviceFor
 *
 * Built-ins only. Reads no credentials itself (wp.js does, at runtime).
 */
const path = require('path');
const { execFileSync } = require('child_process');

const LABELS = { media_audit: 'Media audit', media_clean_up: 'Media clean-up', updates: 'Updates' };

function adviceFor(part, status) {
  if (part === 'page_building') return 'Install divi5-builder.zip from the latest release.';
  const label = LABELS[part];
  if (!label) throw new Error('unknown part: ' + part);
  if (!status || typeof status !== 'object') {
    return `Install divi5-builder.zip from the latest release (Plugins > Add New > Upload Plugin), then switch ${label} on in Settings > Divi 5 Builder.`;
  }
  const sw = status.switches || {};
  if (!sw[part]) return `Switch ${label} on in Settings > Divi 5 Builder (an administrator must do it in wp-admin).`;
  const aside = status.stood_aside && status.stood_aside[part];
  if (aside) return `The ${label} switch is on, but this part stood aside (${aside}): another copy of it is running on the site. Remove that copy, or ask the site's administrator.`;
  return `The ${label} switch is on, but the route did not answer. Check Settings > Divi 5 Builder, and that divi5-builder is version ${status.version || '2.0.0'} or newer.`;
}

// Read-only: runs `wp.js <site> status` (GET divi5-builder/v1/status). Any failure (its own 404 included) is null.
function adviceForSite(site, part, wpjs) {
  let status = null;
  try {
    status = JSON.parse(execFileSync(process.execPath, [wpjs || path.join(__dirname, 'wp.js'), site, 'status'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }));
  } catch (e) { status = null; }
  return adviceFor(part, status);
}

module.exports = { adviceFor, adviceForSite, LABELS };
