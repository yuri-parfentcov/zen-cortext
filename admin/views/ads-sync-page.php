<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — Ads Sync admin page.
 * API key + Google Ads script (copy/paste) + read-only synced campaigns.
 *
 * Available: $key_info (array), $sync_ts (string|null), $sync_count (int)
 */
if (!defined('ABSPATH')) exit;

$rest_root        = esc_url_raw(rest_url('zen-cortext/v1'));
$ingest_endpoint  = $rest_root . '/ingest/ads-campaigns';
$ping_endpoint    = $rest_root . '/ingest/ping';

/*
 * Google Ads script source. Runs inside Google Ads (Tools & Settings →
 * Bulk Actions → Scripts), not Apps Script — uses the AdsApp.search()
 * GAQL API which has native access to campaign / RSA / keyword data
 * without OAuth. {{ENDPOINT_URL}} is substituted with this site's actual
 * REST URL; the API key the admin must paste themselves after generating
 * it via the button above.
 */
$google_ads_script = <<<'JS'
/**
 * Zen Cortext — Google Ads → WordPress sync.
 *
 * Pulls enabled campaigns + RSA headlines + top-performing keywords and
 * POSTs them to your WordPress site's attribution context store. The
 * chat then injects this data into the AI system prompt and uses it to
 * personalize visitor conversations based on which campaign they came
 * from.
 *
 * Setup: see the instructions on the WordPress admin page that gave you
 * this script. Replace API_KEY below with the value generated there.
 */

// ---- CONFIG -----------------------------------------------------------
var ENDPOINT_URL = '{{ENDPOINT_URL}}';
var API_KEY      = 'zcas_PASTE_YOUR_KEY_HERE';

// Tunables. Defaults fit a typical chat-prompt context budget.
var TOP_HEADLINES_LIMIT = 8;
var TOP_KEYWORDS_LIMIT  = 12;
var KEYWORD_LOOKBACK    = 'LAST_30_DAYS'; // GAQL date range
var DELETE_MISSING      = false;          // true = drop WP rows absent from this run

// Which campaign statuses to fetch. The "Campaigns to fetch" dropdown on
// the WordPress admin page rewrites this line; edit it by hand if you prefer.
//   ['ENABLED']            -> active campaigns only
//   ['ENABLED', 'PAUSED']  -> active + paused
//   []                     -> all statuses (including removed)
var CAMPAIGN_STATUSES   = ['ENABLED'];
// -----------------------------------------------------------------------

function main() {
  if (!API_KEY || API_KEY.indexOf('PASTE_YOUR_KEY') !== -1) {
    throw new Error('Set API_KEY at the top of this script (generate one in WordPress → Zen Cortext → Google Ads Sync).');
  }

  var campaigns = collectCampaigns();
  attachHeadlines(campaigns);
  attachKeywords(campaigns);

  var groups = collectAdGroups();
  attachAdGroupHeadlines(groups);
  attachAdGroupKeywords(groups);

  var rows = [];
  for (var id in campaigns) {
    if (campaigns.hasOwnProperty(id)) rows.push(campaigns[id]);
  }
  for (var gid in groups) {
    if (groups.hasOwnProperty(gid)) rows.push(groups[gid]);
  }

  Logger.log('Posting %s campaigns to %s', rows.length, ENDPOINT_URL);

  var response = UrlFetchApp.fetch(ENDPOINT_URL, {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify({ delete_missing: DELETE_MISSING, campaigns: rows }),
    headers: { 'Authorization': 'Bearer ' + API_KEY },
    muteHttpExceptions: true
  });

  Logger.log('Response %s: %s', response.getResponseCode(), response.getContentText());
  if (response.getResponseCode() >= 400) {
    throw new Error('Sync failed — see log.');
  }
}

/** Build a `campaign.status IN (...)` filter from CAMPAIGN_STATUSES. Empty array = no filter (all statuses). */
function campaignStatusClause() {
  if (!CAMPAIGN_STATUSES || !CAMPAIGN_STATUSES.length) return '';
  var quoted = [];
  for (var i = 0; i < CAMPAIGN_STATUSES.length; i++) {
    quoted.push("'" + CAMPAIGN_STATUSES[i] + "'");
  }
  return 'campaign.status IN (' + quoted.join(', ') + ')';
}

/** Campaigns (filtered by CAMPAIGN_STATUSES) into a {campaign_id → row} map. */
function collectCampaigns() {
  var statusClause = campaignStatusClause();
  var query =
    'SELECT campaign.id, campaign.name, campaign.status, campaign_budget.amount_micros ' +
    'FROM campaign' +
    (statusClause ? ' WHERE ' + statusClause : '');
  var iterator = AdsApp.search(query);
  var out = {};
  while (iterator.hasNext()) {
    var row = iterator.next();
    var id = String(row.campaign.id);
    out[id] = {
      type:          'campaign',
      campaign_id:   id,
      campaign_name: row.campaign.name || '',
      ad_group_id:   '',
      ad_group_name: '',
      status:        row.campaign.status || '',
      budget_micros: (row.campaignBudget && row.campaignBudget.amountMicros)
                       ? Number(row.campaignBudget.amountMicros) : null,
      top_headlines: [],
      top_keywords:  []
    };
  }
  return out;
}

/** Pull RSA headlines for each campaign, dedupe, top N. */
function attachHeadlines(campaigns) {
  var query =
    'SELECT campaign.id, ad_group_ad.ad.responsive_search_ad.headlines ' +
    'FROM ad_group_ad ' +
    "WHERE ad_group_ad.ad.type = 'RESPONSIVE_SEARCH_AD' " +
    "AND ad_group_ad.status = 'ENABLED' " +
    "AND ad_group.status = 'ENABLED'" +
    (campaignStatusClause() ? ' AND ' + campaignStatusClause() : '');
  var iterator = AdsApp.search(query);
  var seen = {};
  while (iterator.hasNext()) {
    var row = iterator.next();
    var cid = String(row.campaign.id);
    if (!campaigns[cid]) continue;
    var rsa = row.adGroupAd && row.adGroupAd.ad && row.adGroupAd.ad.responsiveSearchAd;
    var heads = (rsa && rsa.headlines) ? rsa.headlines : [];
    if (!seen[cid]) seen[cid] = {};
    for (var i = 0; i < heads.length; i++) {
      var text = (heads[i] && heads[i].text) ? String(heads[i].text).trim() : '';
      if (!text || seen[cid][text]) continue;
      if (campaigns[cid].top_headlines.length >= TOP_HEADLINES_LIMIT) break;
      seen[cid][text] = true;
      campaigns[cid].top_headlines.push(text);
    }
  }
}

/** Top keywords by impressions for each campaign in the lookback window. */
function attachKeywords(campaigns) {
  var query =
    'SELECT campaign.id, ad_group_criterion.keyword.text, metrics.impressions ' +
    'FROM keyword_view ' +
    "WHERE ad_group.status = 'ENABLED' " +
    "AND ad_group_criterion.status = 'ENABLED' " +
    (campaignStatusClause() ? 'AND ' + campaignStatusClause() + ' ' : '') +
    'AND segments.date DURING ' + KEYWORD_LOOKBACK + ' ' +
    'ORDER BY metrics.impressions DESC ' +
    'LIMIT 2000';
  var iterator = AdsApp.search(query);
  var seen = {};
  while (iterator.hasNext()) {
    var row = iterator.next();
    var cid = String(row.campaign.id);
    if (!campaigns[cid]) continue;
    var kw = (row.adGroupCriterion && row.adGroupCriterion.keyword && row.adGroupCriterion.keyword.text)
               ? String(row.adGroupCriterion.keyword.text).trim() : '';
    if (!kw) continue;
    if (!seen[cid]) seen[cid] = {};
    if (seen[cid][kw]) continue;
    if (campaigns[cid].top_keywords.length >= TOP_KEYWORDS_LIMIT) continue;
    seen[cid][kw] = true;
    campaigns[cid].top_keywords.push(kw);
  }
}

/** Ad groups (honoring CAMPAIGN_STATUSES) into a {ad_group_id → row} map. */
function collectAdGroups() {
  var statusClause = campaignStatusClause();
  var query =
    'SELECT campaign.id, campaign.name, ad_group.id, ad_group.name, ad_group.status ' +
    'FROM ad_group ' +
    "WHERE ad_group.status = 'ENABLED'" +
    (statusClause ? ' AND ' + statusClause : '');
  var iterator = AdsApp.search(query);
  var out = {};
  while (iterator.hasNext()) {
    var row = iterator.next();
    var gid = String(row.adGroup.id);
    out[gid] = {
      type:          'group',
      campaign_id:   String(row.campaign.id),
      campaign_name: row.campaign.name || '',
      ad_group_id:   gid,
      ad_group_name: row.adGroup.name || '',
      status:        row.adGroup.status || '',
      budget_micros: null,
      top_headlines: [],
      top_keywords:  []
    };
  }
  return out;
}

/** RSA headlines per ad group, dedupe, top N. */
function attachAdGroupHeadlines(groups) {
  var query =
    'SELECT ad_group.id, ad_group_ad.ad.responsive_search_ad.headlines ' +
    'FROM ad_group_ad ' +
    "WHERE ad_group_ad.ad.type = 'RESPONSIVE_SEARCH_AD' " +
    "AND ad_group_ad.status = 'ENABLED' " +
    "AND ad_group.status = 'ENABLED'" +
    (campaignStatusClause() ? ' AND ' + campaignStatusClause() : '');
  var iterator = AdsApp.search(query);
  var seen = {};
  while (iterator.hasNext()) {
    var row = iterator.next();
    var gid = String(row.adGroup.id);
    if (!groups[gid]) continue;
    var rsa = row.adGroupAd && row.adGroupAd.ad && row.adGroupAd.ad.responsiveSearchAd;
    var heads = (rsa && rsa.headlines) ? rsa.headlines : [];
    if (!seen[gid]) seen[gid] = {};
    for (var i = 0; i < heads.length; i++) {
      var text = (heads[i] && heads[i].text) ? String(heads[i].text).trim() : '';
      if (!text || seen[gid][text]) continue;
      if (groups[gid].top_headlines.length >= TOP_HEADLINES_LIMIT) break;
      seen[gid][text] = true;
      groups[gid].top_headlines.push(text);
    }
  }
}

/** Top keywords by impressions per ad group in the lookback window. */
function attachAdGroupKeywords(groups) {
  var statusClause = campaignStatusClause();
  var query =
    'SELECT ad_group.id, ad_group_criterion.keyword.text, metrics.impressions ' +
    'FROM keyword_view ' +
    "WHERE ad_group.status = 'ENABLED' " +
    "AND ad_group_criterion.status = 'ENABLED' " +
    (statusClause ? 'AND ' + statusClause + ' ' : '') +
    'AND segments.date DURING ' + KEYWORD_LOOKBACK + ' ' +
    'ORDER BY metrics.impressions DESC ' +
    'LIMIT 5000';
  var iterator = AdsApp.search(query);
  var seen = {};
  while (iterator.hasNext()) {
    var row = iterator.next();
    var gid = String(row.adGroup.id);
    if (!groups[gid]) continue;
    var kw = (row.adGroupCriterion && row.adGroupCriterion.keyword && row.adGroupCriterion.keyword.text)
               ? String(row.adGroupCriterion.keyword.text).trim() : '';
    if (!kw) continue;
    if (!seen[gid]) seen[gid] = {};
    if (seen[gid][kw]) continue;
    if (groups[gid].top_keywords.length >= TOP_KEYWORDS_LIMIT) continue;
    seen[gid][kw] = true;
    groups[gid].top_keywords.push(kw);
  }
}
JS;
$google_ads_script = str_replace('{{ENDPOINT_URL}}', $ingest_endpoint, $google_ads_script);
?>
<div class="wrap zen-cortext-wrap">
<h1><?php esc_html_e('Zen Cortext — Google Ads Sync', 'zen-cortext'); ?></h1>

<div id="zen-cortext-ads-sync-root" class="zen-cortext-ads-sync">

    <h2 style="margin-top:24px;"><?php esc_html_e('1. API key', 'zen-cortext'); ?></h2>
    <p class="description">
        <?php esc_html_e('Used by the Google Ads script to authenticate. The key is shown ONCE on regeneration — paste it into the script immediately.', 'zen-cortext'); ?>
    </p>

    <table class="form-table" role="presentation">
        <tr>
            <th><?php esc_html_e('Status', 'zen-cortext'); ?></th>
            <td>
                <?php if (!empty($key_info['is_set'])): ?>
                    <strong><?php esc_html_e('Set', 'zen-cortext'); ?></strong>
                    <?php if (!empty($key_info['last4'])): ?>
                        — <code>zcas_…<?php echo esc_html($key_info['last4']); ?></code>
                    <?php endif; ?>
                    <?php if (!empty($key_info['rotated_at'])): ?>
                        <br><span class="description"><?php
                            /* translators: %s is a datetime string */
                            printf(esc_html__('Last rotated: %s', 'zen-cortext'), esc_html($key_info['rotated_at']));
                        ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <em><?php esc_html_e('No key configured yet.', 'zen-cortext'); ?></em>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Endpoint', 'zen-cortext'); ?></th>
            <td>
                <code><?php echo esc_html($ingest_endpoint); ?></code><br>
                <span class="description"><?php esc_html_e('Health check: ', 'zen-cortext'); ?><code><?php echo esc_html($ping_endpoint); ?></code></span>
            </td>
        </tr>
        <tr>
            <th></th>
            <td>
                <button type="button" class="button button-primary" id="zat-key-regen">
                    <?php echo !empty($key_info['is_set'])
                        ? esc_html__('Regenerate key (invalidates the old one)', 'zen-cortext')
                        : esc_html__('Generate API key', 'zen-cortext'); ?>
                </button>
                <span id="zat-key-status" class="zca-status"></span>
            </td>
        </tr>
        <tr id="zat-key-revealed-row" style="display:none;">
            <th><?php esc_html_e('New key', 'zen-cortext'); ?></th>
            <td>
                <input type="text" id="zat-key-revealed" readonly class="large-text code" />
                <p class="description" style="color:#b32d2e;">
                    <strong><?php esc_html_e('Copy this now — it will not be shown again.', 'zen-cortext'); ?></strong>
                </p>
                <button type="button" class="button" id="zat-key-copy"><?php esc_html_e('Copy to clipboard', 'zen-cortext'); ?></button>
            </td>
        </tr>
    </table>

    <h2 style="margin-top:36px;"><?php esc_html_e('2. Google Ads script', 'zen-cortext'); ?></h2>
    <p class="description">
        <?php esc_html_e('Runs inside Google Ads — not generic Apps Script — using the native AdsApp API. No OAuth setup required: Google Ads scripts have built-in access to your account data. It also pulls ad-group-level headlines & keywords in the same run.', 'zen-cortext'); ?>
    </p>

    <ol class="zat-steps">
        <li><?php esc_html_e('Generate the API key above and copy it.', 'zen-cortext'); ?></li>
        <li><?php
            printf(
                /* translators: %s is the menu path "Tools and Settings → Bulk Actions → Scripts" inside the Google Ads UI. */
                esc_html__('In Google Ads, go to %s and click "+ New script" → "Empty script".', 'zen-cortext'),
                '<strong>' . esc_html__('Tools and Settings → Bulk Actions → Scripts', 'zen-cortext') . '</strong>'
            );
        ?></li>
        <li><?php esc_html_e('Click "Copy script" below, then paste into the Google Ads script editor (replacing the empty template).', 'zen-cortext'); ?></li>
        <li><?php
            printf(
                /* translators: %s is the API_KEY constant name in the Google Ads script. */
                esc_html__('At the top of the script, replace %s with the key you copied. The endpoint URL is already filled in for this site.', 'zen-cortext'),
                '<code>API_KEY</code>'
            );
        ?></li>
        <li><?php esc_html_e('Click "Preview" → authorize the script when prompted (it needs permission to read your campaigns and to fetch external URLs).', 'zen-cortext'); ?></li>
        <li><?php esc_html_e('Once preview succeeds, click "Save" → "Run", then schedule it: "Frequency" → Daily (or Hourly for fast-changing accounts).', 'zen-cortext'); ?></li>
        <li><?php esc_html_e('Within a minute of the first run, the synced campaigns will appear in the table at the bottom of this page.', 'zen-cortext'); ?></li>
    </ol>

    <p class="zat-script-config">
        <label for="zat-script-statuses"><strong><?php esc_html_e('Campaigns to fetch:', 'zen-cortext'); ?></strong></label>
        <select id="zat-script-statuses">
            <option value="ENABLED"><?php esc_html_e('Enabled only', 'zen-cortext'); ?></option>
            <option value="ENABLED,PAUSED"><?php esc_html_e('Enabled and paused', 'zen-cortext'); ?></option>
            <option value="ALL"><?php esc_html_e('All (including removed)', 'zen-cortext'); ?></option>
        </select>
        <span class="description"><?php esc_html_e('Sets which campaign statuses the script syncs. Updates the script below — choose this before you copy.', 'zen-cortext'); ?></span>
    </p>
    <p>
        <button type="button" class="button button-primary" id="zat-script-copy">
            <?php esc_html_e('Copy script', 'zen-cortext'); ?>
        </button>
        <span id="zat-script-status" class="zca-status"></span>
    </p>
    <textarea id="zat-script-source" class="zat-script-source code" readonly rows="22" spellcheck="false"><?php
        echo esc_textarea($google_ads_script);
    ?></textarea>

    <p class="description" style="margin-top:8px;">
        <strong><?php esc_html_e('Tunables at the top of the script:', 'zen-cortext'); ?></strong>
        <code>TOP_HEADLINES_LIMIT</code>, <code>TOP_KEYWORDS_LIMIT</code>, <code>KEYWORD_LOOKBACK</code>,
        <code>DELETE_MISSING</code>, <code>CAMPAIGN_STATUSES</code>.
        <?php esc_html_e('Leave DELETE_MISSING = false unless you want every sync to delete campaigns that this script no longer reports — useful only when the script is the single source of truth.', 'zen-cortext'); ?>
    </p>

    <h2 style="margin-top:36px;"><?php esc_html_e('3. Synced campaigns', 'zen-cortext'); ?></h2>
    <p class="description" id="zat-ads-summary">
        <?php
        if ($sync_count > 0 && $sync_ts) {
            printf(
                /* translators: %1$d is the number of synced Google Ads rows (campaigns + ad groups), %2$s is the last-sync datetime. */
                esc_html__('%1$d synced rows. Last sync: %2$s.', 'zen-cortext'),
                (int) $sync_count,
                esc_html($sync_ts)
            );
        } else {
            esc_html_e('No synced rows yet. Run the script in Google Ads to populate this list.', 'zen-cortext');
        }
        ?>
    </p>
    <p>
        <button type="button" class="button button-link-delete" id="zat-ads-clear" <?php disabled($sync_count <= 0); ?>>
            <?php esc_html_e('Clear synced data', 'zen-cortext'); ?>
        </button>
        <span id="zat-ads-clear-status" class="zca-status"></span>
        <br>
        <span class="description"><?php esc_html_e('Wipes every row in the synced-campaigns table. Attribution rules are NOT affected — they keep matching on raw UTMs; they just lose the joined Google Ads metadata until the next sync run repopulates it.', 'zen-cortext'); ?></span>
    </p>

    <table class="widefat striped" id="zat-ads-list">
        <thead>
            <tr>
                <th><?php esc_html_e('Type', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Campaign', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Ad group', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('ID', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Status', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Budget', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Headlines', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Keywords', 'zen-cortext'); ?></th>
                <th><?php esc_html_e('Last sync', 'zen-cortext'); ?></th>
            </tr>
        </thead>
        <tbody id="zat-ads-list-body">
            <tr><td colspan="9"><em><?php esc_html_e('Loading…', 'zen-cortext'); ?></em></td></tr>
        </tbody>
    </table>
</div>

</div>
