<?php

defined( 'ABSPATH' ) || exit;


/**
 * Dynamic block Portfolio output.
 *
 * @param [type] $block_attributes
 * @param [type] $content
 *
 * @return void
 */
function trader_dynamic_block_portfolio_cb( $block_attributes, $content )
{
  /**
   * Check user capabilities.
   */
  $current_user = wp_get_current_user();
  if ( ! current_user_can( 'trader_manage_portfolio' ) ) {
    return;
  }

  $args = \Trader\get_args_from_request_params();

  $errors = get_error_obj();

  $assets_weightings = get_user_meta( $current_user->ID, 'asset_weightings', true );
  $assets_weightings = is_array( $assets_weightings ) ? $assets_weightings : array();

  $balance_allocated = \Trader\get_asset_allocations( $assets_weightings, $args );

  if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
    if ( isset( $_POST['action'] )
      && isset( $_POST['do-portfolio-rebalance-nonce'] ) && wp_verify_nonce( $_POST['do-portfolio-rebalance-nonce'], 'portfolio-rebalance-user_' . $current_user->ID )
    ) {
      switch ( $_POST['action'] ) {

        case 'do-portfolio-rebalance':
          $balance_exchange = \Trader\Exchanges\Bitvavo::get_balance();
          $balance          = \Trader\merge_balance( $balance_allocated, $balance_exchange, $args );

          if ( is_wp_error( $balance_allocated ) ) {
            $errors->merge_from( $balance_allocated );
          }
          if ( is_wp_error( $balance_exchange ) ) {
            $errors->merge_from( $balance_exchange );
          }

          if ( is_wp_error( $balance_allocated ) || is_wp_error( $balance_exchange ) ) {
            break;
          }

          foreach ( \Trader\rebalance( $balance, 'default' ) as $index => $order ) {
            if ( ! empty( $order['errorCode'] ) ) {
              $errors->add(
                $order['errorCode'] . '-' . $index,
                sprintf( __( 'Exchange error %1$s %2$s: ', 'trader' ), $order['side'], $order['market'] ) . ( $order['error'] ?? __( 'An unknown error occured.', 'trader' ) )
              );
            }
          }

          break;

        case 'sell-whole-portfolio':
          foreach ( \Trader\Exchanges\Bitvavo::sell_whole_portfolio() as $index => $order ) {
            if ( ! empty( $order['errorCode'] ) ) {
              $errors->add(
                $order['errorCode'] . '-' . $index,
                sprintf( __( 'Exchange error %1$s %2$s: ', 'trader' ), $order['side'], $order['market'] ) . ( $order['error'] ?? __( 'An unknown error occured.', 'trader' ) )
              );
            }
          }

          break;
      }
    } else {
      $errors->add( 'submit_error', __( 'Action failed.', 'trader' ) );
    }
  }

  $balance_exchange = \Trader\Exchanges\Bitvavo::get_balance();
  $balance          = \Trader\merge_balance( $balance_allocated, $balance_exchange, $args );

  if ( is_wp_error( $balance_allocated ) ) {
    $errors->merge_from( $balance_allocated );
  }
  if ( is_wp_error( $balance_exchange ) ) {
    $errors->merge_from( $balance_exchange );
  }

  ob_start();

  if ( $errors->has_errors() ) :
    ?><div class="error"><p><?php echo implode( "</p>\n<p>", $errors->get_error_messages() ); ?></p></div>
    <?php
  endif;

  trader_echo_balance_summary( $balance_exchange );

  trader_echo_portfolio( $balance );

  trader_echo_onchain_summary();

  if ( ! is_wp_error( $balance_allocated ) && ! is_wp_error( $balance_exchange ) ) :
    $expected_fee = 0;
    foreach ( \Trader\rebalance( $balance, 'default', array(), true ) as $fake_order ) {
      $expected_fee = bcadd( $expected_fee, trader_ceil( $fake_order['feePaid'] ?? 0, 2 ) );
    }
    $expected_fee = number_format( trader_ceil( $expected_fee, 2 ), 2 );

    /**
     * WIP, WILL BE FURTHER IMPROVED FOR UX !!
     */
    ?>
    <form action="<?php echo esc_attr( get_permalink() ); ?>" method="get">
      <!-- <p class="form-row">
        <label>
          <?php esc_html_e( 'Interval days', 'trader' ); ?> [n]&nbsp;
          <span style="display:inline-block;"><?php echo esc_html( sprintf( __( '(current = %s)', 'trader' ), $args['interval_days'] ) ); ?></span>
          <input type="number" min="1" class="input-number" name="interval_days" value="<?php echo esc_attr( $args['interval_days'] ); ?>" />
        </label>
      </p> -->
      <p class="form-row form-row-first">
        <label>
          <?php esc_html_e( 'Top count', 'trader' ); ?> [n]&nbsp;
          <span style="display:inline-block;"><?php echo esc_html( sprintf( __( '(current = %s)', 'trader' ), $args['top_count'] ) ); ?></span>
          <input type="number" min="1" class="input-number" name="top_count" value="<?php echo esc_attr( $args['top_count'] ); ?>" />
        </label>
      </p>
      <p class="form-row form-row-last">
        <label>
          <?php esc_html_e( 'Market cap ^(1/[n])', 'trader' ); ?>&nbsp;
          <span style="display:inline-block;"><?php echo esc_html( sprintf( __( '(current = %s)', 'trader' ), $args['sqrt'] ) ); ?></span>
          <input type="number" min="1" class="input-number" name="sqrt" value="<?php echo esc_attr( $args['sqrt'] ); ?>" />
        </label>
      </p>
      <div class="clear"></div>
      <p class="form-row form-row-first">
        <label>
          <?php esc_html_e( 'Quote allocation', 'trader' ); ?> [%]&nbsp;
          <span style="display:inline-block;"><?php echo esc_html( sprintf( __( '(current = %s)', 'trader' ), $args['alloc_quote'] ) ); ?></span>
          <input type="number" min="0" class="input-number" name="alloc_quote" value="<?php echo esc_attr( $args['alloc_quote'] ); ?>" />
        </label>
      </p>
      <p class="form-row form-row-last">
        <label>
          <?php esc_html_e( 'Quote takeout', 'trader' ); ?> [€]&nbsp;
          <span style="display:inline-block;"><?php echo esc_html( sprintf( __( '(current = %s)', 'trader' ), $args['takeout'] ) ); ?></span>
          <input type="number" min="0" class="input-number" name="takeout" value="<?php echo esc_attr( $args['takeout'] ); ?>" />
        </label>
      </p>
      <div class="clear"></div>
      <p>
        <button type="submit" class="button" value="<?php esc_attr_e( 'Refresh', 'trader' ); ?>"><?php esc_html_e( 'Refresh', 'trader' ); ?></button>
      </p>
    </form>
    <form style="display:inline-block;" action="<?php echo esc_attr( get_permalink() ); ?>" method="post">
      <?php wp_nonce_field( 'portfolio-rebalance-user_' . $current_user->ID, 'do-portfolio-rebalance-nonce' ); ?>
      <p>
        <input type="hidden" name="action" value="do-portfolio-rebalance" />
        <input type="hidden" name="top_count" value="<?php echo esc_attr( $args['top_count'] ); ?>" />
        <input type="hidden" name="sqrt" value="<?php echo esc_attr( $args['sqrt'] ); ?>" />
        <input type="hidden" name="alloc_quote" value="<?php echo esc_attr( $args['alloc_quote'] ); ?>" />
        <input type="hidden" name="takeout" value="<?php echo esc_attr( $args['takeout'] ); ?>" />
        <button type="submit" class="button trader-action-zone" value="<?php echo esc_attr( sprintf( __( 'Rebalance now (fee ≈ € %s)', 'trader' ), $expected_fee ) ); ?>"
        onclick="return confirm('<?php esc_attr_e( 'This will perform a portfolio rebalance.\nAre you sure?', 'trader' ); ?>');"><?php echo esc_html( sprintf( __( 'Rebalance now (fee ≈ € %s)', 'trader' ), $expected_fee ) ); ?></button>
      </p>
    </form>
    <form style="display:inline-block;" action="<?php echo esc_attr( get_permalink() ); ?>" method="post">
      <?php wp_nonce_field( 'portfolio-rebalance-user_' . $current_user->ID, 'do-portfolio-rebalance-nonce' ); ?>
      <p>
        <input type="hidden" name="action" value="sell-whole-portfolio" />
        <input type="hidden" name="top_count" value="<?php echo esc_attr( $args['top_count'] ); ?>" />
        <input type="hidden" name="sqrt" value="<?php echo esc_attr( $args['sqrt'] ); ?>" />
        <input type="hidden" name="alloc_quote" value="<?php echo esc_attr( $args['alloc_quote'] ); ?>" />
        <input type="hidden" name="takeout" value="<?php echo esc_attr( $args['takeout'] ); ?>" />
        <button type="submit" class="button trader-danger-zone" value="<?php esc_attr_e( 'Sell whole portfolio', 'trader' ); ?>"
        onclick="return confirm('<?php esc_attr_e( 'This will sell all your assets.\nAre you sure?', 'trader' ); ?>');"><?php esc_html_e( 'Sell whole portfolio', 'trader' ); ?></button>
      </p>
    </form>

    <?php
  endif;

  return ob_get_clean();
}
