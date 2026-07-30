<?php
/**
 * BT Quote — [bt_quick_quote] shortcode.
 *
 * The Quick Quote tool UI, ported in from the BT-Quick-Quote-Tool WPCode
 * snippet so the tool and the pricing engine live in one place and ship
 * together on plugin updates.
 *
 * The /quote/ page needs nothing but:  [bt_quick_quote]
 *
 * Optional attributes set the starting selections (a URL query param of the
 * same name overrides them, so a shared link always wins):
 *
 *   [bt_quick_quote qty="48" garment="g5000" locations="2"]
 *   [bt_quick_quote method="emb" embtype="logo" garment="supplied"]
 *
 * IMPORTANT: deactivate the old BT-Quick-Quote-Tool snippet. Two copies of
 * this shortcode registered at once means whichever loads last wins, which
 * is not something to leave to chance. The BT Quote admin page reports
 * which copy is actually serving the shortcode.
 */

if (!defined('ABSPATH')) exit;

add_shortcode('bt_quick_quote', 'btq_quick_quote_shortcode');

/** Marker so the admin page can tell whether our copy is the live one. */
function btq_shortcode_owner() {
    global $shortcode_tags;
    if (empty($shortcode_tags['bt_quick_quote'])) return 'none';
    return ($shortcode_tags['bt_quick_quote'] === 'btq_quick_quote_shortcode') ? 'plugin' : 'other';
}

function btq_quick_quote_shortcode($atts = array()) {
    static $rendered = false;
    if ($rendered) {
        return '<!-- BT Quote: [bt_quick_quote] already rendered on this page; ignoring duplicate. -->';
    }
    $rendered = true;

    $a = shortcode_atts(array(
        'qty'       => '',
        'garment'   => '',
        'locations' => '',
        'method'    => '',
        'embtype'   => '',
        'retail'    => '',
    ), $atts, 'bt_quick_quote');

    // Short keys, matching the query-string params the JS reads.
    $defaults = array(
        'qty' => $a['qty'],
        'g'   => $a['garment'],
        'loc' => $a['locations'],
        'm'   => $a['method'],
        'et'  => $a['embtype'],
        'r'   => $a['retail'],
    );

    wp_enqueue_style(
        'btq-oswald',
        'https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&display=swap',
        array(),
        null
    );
    wp_enqueue_style('btq-quick-quote', BTQ_URL . 'assets/quick-quote.css', array(), BTQ_VERSION);
    wp_enqueue_script('btq-quick-quote', BTQ_URL . 'assets/quick-quote.js', array(), BTQ_VERSION, true);
    wp_add_inline_script(
        'btq-quick-quote',
        'window.BTQ_QQ = ' . wp_json_encode(array(
            'apiBase'  => home_url('/wp-json/boomerts/v1'),
            'defaults' => $defaults,
        )) . ';',
        'before'
    );

    ob_start();
    ?>
<div class="bt-tool" id="btQuoteRoot">

  <div class="bt-method" id="btMethodSel"></div>

  <div class="bt-grid">
    <div class="left-col">

      <div class="bt-card">
        <div class="step-title"><span class="step-num">1</span>Quantity</div>
        <div class="qty-row">
          <button type="button" class="qty-btn" onclick="btAdj(-1)">&minus;</button>
          <input type="number" class="qty-num" id="btQty" value="12" min="1" max="1000" oninput="btCalc()">
          <button type="button" class="qty-btn" onclick="btAdj(1)">+</button>
        </div>
        <div class="qty-note">No Minimums &middot; Save with Every Shirt &middot; No Price Tiers to Reach.</div>
      </div>

      <div class="bt-card">
        <div class="step-title"><span class="step-num">2</span><span id="btStep2Title">Print Locations</span></div>
        <div class="opt-list" id="btLocList"></div>
      </div>

    </div>

    <div class="bt-card">
      <div class="step-title">
        <span class="step-num">3</span>Garment Type
        <span class="step-title-note">Don't see what you want? We can get it!<br>Just let us know when you submit your quote.</span>
      </div>
      <div class="gmt-grid" id="btGmtList"></div>
    </div>
  </div>

  <div class="section-divider">
    <div class="section-divider-line"></div>
    <div class="section-divider-label">&#8595; Your Results</div>
    <div class="section-divider-line"></div>
  </div>

  <div class="bt-bottom">
    <div class="bt-results">
      <div class="results-layout">
        <div class="price-side" id="btPriceSide">
          <div class="price-eyebrow">Price Per Shirt</div>
          <div class="price-display">
            <span class="price-dollars" id="btDlr">&mdash;</span>
            <span class="price-cents" id="btCts"></span>
          </div>
          <div id="btDiscount" style="display:none;margin-top:6px;">
            <span id="btDiscountBadge" style="font-family:'Oswald',sans-serif;font-size:15px;font-weight:500;letter-spacing:1px;color:#e535ab;">&mdash;</span>
          </div>
        </div>
        <div class="summary-side">
          <div class="stat-row">
            <div class="stat-box"><div class="stat-lbl">Qty</div><div class="stat-val" id="btSQty">&mdash;</div></div>
            <div class="stat-box"><div class="stat-lbl" id="btSLocLbl">Locations</div><div class="stat-val" id="btSLoc">&mdash;</div></div>
            <div class="stat-box"><div class="stat-lbl">Garment</div><div class="stat-val small" id="btSGmt">&mdash;</div></div>
            <div class="stat-box"><div class="stat-lbl">Order Total</div><div class="stat-val" id="btSTotal">&mdash;</div></div>
          </div>
          <div class="brk-wrap">
            <div class="brk-row brk-total" style="border-top:none;margin-top:0;padding-top:0;">
              <span class="bl" id="btTotalLbl">Total</span>
              <span class="bv" id="btBTotal">&mdash;</span>
            </div>
            <div style="margin-top:5px;font-size:10px;font-weight:600;color:rgba(255,255,255,0.4);letter-spacing:1.5px;text-transform:uppercase;text-align:right;">All-In &middot; No Setup Fees</div>
          </div>
        </div>
        <div class="order-btn">
          <button type="button" onclick="btOpenModal()">Let's Get Started On Your Order!</button>
        </div>
      </div>
    </div>

    <div class="tbl-wrap">
      <div class="tbl-scroll" id="btTblScroll">
        <table>
          <thead>
            <tr><th>Quantity (Approx)</th><th>Price / Shirt</th><th>Order Total</th></tr>
          </thead>
          <tbody id="btTbody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="bt-share">
    <button type="button" class="bt-share-btn" id="btShareBtn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
      Copy Quote Link
    </button>
    <span class="bt-share-msg" id="btShareMsg"></span>
    <input type="text" class="bt-share-url" id="btShareUrl" readonly onclick="this.select();">
  </div>

  <div class="bt-foot">
    <span id="btFootText">Pricing based on full color prints, by location. Estimates only &mdash;
    <strong>contact Boomer T's</strong> to confirm pricing and get started.
    All prices include setup.</span>
  </div>

</div>

<!-- Modal -->
<div class="bt-modal-overlay" id="btModalOverlay">
  <div class="bt-modal">
    <div class="bt-modal-head">
      <button type="button" class="bt-modal-close" onclick="btCloseModal()">&#10005;</button>
      <h2>Let's Get Your Order Started</h2>
      <p>We'll reach out within 1 business day to confirm your quote and next steps.</p>
    </div>
    <div class="bt-modal-summary" id="btModalSummary"></div>
    <div class="bt-modal-body" id="btModalForm">
      <div class="bt-field-row">
        <div class="bt-field">
          <label>Your Name *</label>
          <input type="text" id="btFName" placeholder="Jane Smith">
        </div>
        <div class="bt-field">
          <label>Email Address *</label>
          <input type="email" id="btFEmail" placeholder="jane@example.com">
        </div>
      </div>
      <div class="bt-field">
        <label>Organization / Group</label>
        <input type="text" id="btFOrg" placeholder="Team name, school, company, event&hellip;">
      </div>
      <div class="bt-field">
        <label>Phone (optional)</label>
        <input type="tel" id="btFPhone" placeholder="(555) 867-5309">
      </div>
      <div class="bt-field">
        <label>Message / Notes</label>
        <textarea id="btFMsg" placeholder="Tell us about your design, colors, deadline, or any questions&hellip;"></textarea>
      </div>
    </div>
    <div class="bt-modal-footer" id="btModalFooter">
      <button type="button" class="bt-cancel-btn" onclick="btCloseModal()">Cancel</button>
      <button type="button" class="bt-submit-btn" id="btSubmitBtn" onclick="btSubmitForm()">Send My Quote Request &rarr;</button>
    </div>
    <div class="bt-modal-success" id="btModalSuccess">
      <div class="check">&#9989;</div>
      <h3>Request Sent!</h3>
      <p>Thanks! We've received your quote request and will be in touch within 1 business day.<br><br>
      Keep an eye on your inbox &mdash; we'll send a confirmation shortly.</p>
    </div>
  </div>
</div>
    <?php
    return ob_get_clean();
}
