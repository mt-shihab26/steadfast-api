<?php

/**
 * All functions
 */
if (! function_exists('stdf_generate_item_description')) {
    /**
     * Generate comma-separated item descriptions for an order
     *
     * @param  int  $order_id  The WooCommerce order ID
     * @return string Comma-separated item descriptions
     */
    function stdf_generate_item_description($order_id)
    {
        $order = wc_get_order($order_id);

        if (! $order) {
            return '';
        }

        $item_names = [];

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();

            if (! $product) {
                continue;
            }

            $item_names[] = $item->get_name();
        }

        return implode(', ', $item_names);
    }
}

if (! function_exists('send_order_to_steadfast_api')) {
    /**
     * Send Data To SteadFast Api.
     */
    function send_order_to_steadfast_api($order_id): string
    {

        $checkbox = get_option('stdf_settings_tab_checkbox', false);
        $api_secret_key = get_option('api_settings_tab_api_secret_key', false);
        $api_key = get_option('api_settings_tab_api_key', false);
        $api_notes = get_option('stdf_settings_tab_notes', false);

        $order = new WC_Order($order_id);
        $order_data = $order->get_data();

        $input_amount = get_post_meta($order_id, 'steadfast_amount', true);
        $input_amount = ! empty($input_amount) || $input_amount == 0 ? $input_amount : $order_data['total'];

        $fast_name = $order_data['billing']['first_name'];
        $last_name = $order_data['billing']['last_name'];
        $order_billing_address = $order_data['billing']['address_1'];
        $order_billing_phone = $order_data['billing']['phone'];
        $order_shipping_city = $order_data['billing']['city'];
        $order_shipping_postcode = $order_data['billing']['postcode'];

        $order_note = $api_notes == 'yes' ? $order->get_customer_note() : '';

        // Check Customer Valid Phone Number.
        $n = 10;
        $number = strlen($order_billing_phone) - $n;
        $phone = substr($order_billing_phone, $number);
        $customer_phone = '0'.$phone;

        $recipient_address = $order_billing_address.','.$order_shipping_city.'-'.$order_shipping_postcode;
        $body = [
            'invoice' => gmdate('ymj').'-'.$order_id,
            'recipient_name' => $fast_name.' '.$last_name,
            'recipient_phone' => $customer_phone,
            'recipient_address' => $recipient_address,
            'cod_amount' => $input_amount,
            'note' => $order_note,
            'item_description' => stdf_generate_item_description($order_id),
        ];

        $args = [
            'method' => 'POST',
            'headers' => [
                'content-type' => 'application/json',
                'api-key' => sanitize_text_field($api_key),
                'secret-key' => sanitize_text_field($api_secret_key),
            ],
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'body' => wp_json_encode($body),
            'cookies' => [],
        ];
        if ($checkbox == 'yes') {
            $response = wp_remote_post('https://portal.packzy.com/api/v1/create_order', $args);

            $request = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($request['status']) && $request['status'] == 400 && isset($request['errors'])) {
                $errors = $request['errors'];

                foreach ($errors as $field => $messages) {
                    foreach ($messages as $message) {
                        return $message;
                    }
                }
            }

            if ($request['status'] == 200) {
                $consignment_id = $request['consignment']['consignment_id'];
                update_post_meta($order_id, 'steadfast_consignment_id', $consignment_id);

                return esc_html__('success', 'steadfast-api');
            }
        }

        return esc_html__('unauthorized', 'steadfast-api');
    }
}

if (! function_exists('stdf_get_order_customer_details')) {
    /**
     * @throws Exception
     */
    function stdf_get_order_customer_details($order_id): array
    {
        $order = wc_get_order($order_id);
        $order_details = [];

        if ($order) {

            $order_data = $order->get_data();
            $payment_method = $order->get_payment_method_title() ?? '';

            $customer_id = $order->get_customer_id();
            $customer = new WC_Customer($customer_id);

            $input_amount = get_post_meta($order_id, 'steadfast_amount', true);
            $cod_amount = ! empty($input_amount) || $input_amount == 0 ? (int) $input_amount : (int) $order_data['total'];

            $total = $order->get_total();
            $billing_first_name = $order->get_billing_first_name() ?? '';
            $billing_last_name = $order->get_billing_last_name() ?? '';
            $full_name = $billing_first_name.' '.$billing_last_name;
            $email = $customer->get_email();
            $customer_phone = $customer->get_billing_phone();

            $address_info = [];

            $billing_address_1 = $customer->get_billing_address_1();
            if (! empty($billing_address_1)) {
                $address_info[] = $billing_address_1;
            }

            $billing_city = $customer->get_billing_city();
            if (! empty($billing_city)) {
                $address_info[] = $billing_city;
            }

            $billing_postcode = $customer->get_billing_postcode();
            if (! empty($billing_postcode)) {
                $address_info[] = $billing_postcode;
            }

            $billing_country = $customer->get_billing_country();
            if (! empty($billing_country)) {
                $address_info[] = $billing_country;
            }

            $order_details = [
                'customer_name' => $full_name,
                'customer_email' => $email,
                'customer_phone' => $customer_phone,
                'customer_address' => $address_info,
                'cod_amount' => $cod_amount,
                'payment_method' => $payment_method,
            ];
        }

        return $order_details;
    }
}

if (! function_exists('stdf_get_product_details')) {
    /**
     * @return array
     */
    function stdf_get_product_details($order_id)
    {
        $order = wc_get_order($order_id);
        $data = [];

        if ($order) {
            foreach ($order->get_items() as $item_id => $item) {

                $product = $item->get_product() ?? '';
                $name = $item->get_name() ?? '';
                $quantity = $item->get_quantity() ?? '';
                $subtotal = $item->get_subtotal() ?? '';
                $price = $product->get_price() ?? '';
                $description = get_post($item['product_id'])->post_content;

                $words = explode(' ', $description);
                if (count($words) > 7) {
                    $words = array_slice($words, 0, 7);
                    $short_desc = implode(' ', $words).'...';
                }

                $data[] = [
                    'name' => $name,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'price' => $price,
                    'description' => $short_desc,
                ];
            }
        }

        return $data;
    }
}

if (! function_exists('stdf_get_shipping_cost')) {
    /**
     * @return string
     */
    function stdf_get_shipping_cost($order_id)
    {

        $order = wc_get_order($order_id);
        $shipping_total = $order->get_shipping_total();

        if ($shipping_total) {
            return $shipping_total;
        } else {
            return 00;
        }
    }
}

if (! function_exists('stdf_get_product_sku_id')) {
    /**
     * @return array
     */
    function stdf_get_product_sku_id($order_id)
    {
        $item_sku = [];
        $order = wc_get_order($order_id);

        foreach ($order->get_items() as $item) {
            $product = wc_get_product($item->get_product_id());
            $item_sku[] = $product->get_sku();
        }

        return $item_sku;
    }
}

if (! function_exists('stdf_get_status_by_consignment_id')) {
    /**
     * @return mixed|string
     */
    function stdf_get_status_by_consignment_id($consignment_id)
    {

        $checkbox = get_option('stdf_settings_tab_checkbox', false);
        $api_secret_key = get_option('api_settings_tab_api_secret_key', false);
        $api_key = get_option('api_settings_tab_api_key', false);

        $args = [
            'method' => 'GET',
            'headers' => [
                'content-type' => 'application/json',
                'api-key' => sanitize_text_field($api_key),
                'secret-key' => sanitize_text_field($api_secret_key),
            ],
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'cookies' => [],
        ];

        if ($checkbox == 'yes') {
            $response = wp_remote_get('https://portal.packzy.com/api/v1/status_by_cid/'.$consignment_id, $args);

            $request = json_decode(wp_remote_retrieve_body($response), true);
            if ($request['status'] == '200') {
                return $request;
            } else {
                return 'unauthorized';
            }
        } else {
            return 'failed';
        }
    }
}

if (! function_exists('stdf_add_custom_column_content_order_page')) {
    /**
     * @return void
     */
    function stdf_add_custom_column_content_order_page($column, $order = '')
    {

        if (empty($order)) {
            global $post, $stdf_the_order;

            if (empty($stdf_the_order) || ! is_a($stdf_the_order, 'WC_Order') || $stdf_the_order->get_id() !== $post->ID) {
                $stdf_the_order = wc_get_order($post->ID);
            }

            $order_id = is_a($stdf_the_order, 'WC_Order') ? $stdf_the_order->get_id() : null;
        } else {
            $order_id = $order->get_id();
        }

        $meta_value = get_post_meta($order_id, 'steadfast_is_sent', true);
        $classes = $meta_value == 'yes' ? esc_html__('steadfast-send-success', 'steadfast-api') : esc_html__('steadfast_send', 'steadfast-api');

        if ($column === 'send_steadfast') {
            ?>
			<button class="<?php echo esc_attr($classes) ?>" data-stdf-order-nonce="<?php echo esc_attr(wp_create_nonce('stdf_send_order')) ?>" data-order-id="<?php echo esc_attr($order_id) ?>" name="steadfast"><?php echo esc_html__('Send', 'steadfast-api'); ?></button>
			<?php
        }

        $consignment_id = (get_post_meta($order_id, 'steadfast_consignment_id', true)) ? esc_html(get_post_meta($order_id, 'steadfast_consignment_id', true)) : '';

        $site_url = add_query_arg(
            [
                'order_id' => $order_id,
                'consignment_id' => $consignment_id,
            ],
            admin_url('/index.php?page=stdf-invoice')
        );

        if (! empty($consignment_id)) {
            if ($column === 'consignment_id') {
                printf('<div class="std-consignment-id">%s</div>', esc_html($consignment_id));
            }

            if ($column === 'print_details') {
                $nonce_url = wp_nonce_url($site_url, 'stdf_print_order_nonce');
                printf('<div><a class="std-print-order-detail" target="_blank" href="%s">%s</a></div>', esc_url(urldecode($nonce_url)), esc_html__('Print', 'steadfast-api'));
            }

            $delivery_status = get_post_meta($order_id, 'stdf_delivery_status', true);
            $status = ucfirst($delivery_status) ?? '';

            $explode = explode('_', $delivery_status);
            $implode = implode('-', $explode);
            $status_class = ! empty($implode) ? 'std-'.$implode : '';

            if ($column === 'delivery_status') { ?>
				<?php if (empty($delivery_status)) { ?>
					<div class="std-order-status">
						<button id="std-delivery-status" data-stdf-status="<?php echo esc_attr(wp_create_nonce('stdf_delivery_status_nonce')) ?>" data-order-id="<?php echo esc_attr($order_id); ?>" data-consignment-id="<?php echo esc_attr($consignment_id); ?>"><?php echo esc_html__('Check', 'steadfast-api'); ?></button>
						<div id="std-re-check-delivery-status" class=" hidden dashicons dashicons-image-rotate" data-stdf-status="<?php echo esc_attr(wp_create_nonce('stdf_delivery_status_nonce')) ?>" data-order-id="<?php echo esc_attr($order_id); ?>" data-consignment-id="<?php echo esc_attr($consignment_id); ?>"></div>
						<span id="std-current-status" data-status-id="<?php echo esc_attr($order_id); ?>" class="hidden <?php echo esc_attr($status_class) ?>"><?php echo esc_html($status); ?></span>
					</div>
				<?php } else { ?>
					<div class="std-order-status">
						<div id="std-re-check-delivery-status" class="dashicons dashicons-image-rotate" data-stdf-status="<?php echo esc_attr(wp_create_nonce('stdf_delivery_status_nonce')) ?>" data-order-id="<?php echo esc_attr($order_id); ?>" data-consignment-id="<?php echo esc_attr($consignment_id); ?>"></div>
						<span id="std-current-status" data-status-id="<?php echo esc_attr($order_id); ?>" class="<?php echo esc_attr($status_class) ?>"><?php echo esc_html($status); ?></span>
					</div>
			<?php }
				}
        }

        $amnt_class = $meta_value == 'yes' ? 'amount-disable' : '';
        $input_value = get_post_meta($order_id, 'steadfast_amount', true);
        $success_ratio = get_post_meta($order_id, 'stdf_success_ratio', true) ? get_post_meta($order_id, 'stdf_success_ratio', true) : 'Refresh';

        if ($column === 'amount') { ?>
			<input type="text" id="steadfast-amount" data-stdf-amount="<?php echo esc_attr(wp_create_nonce('stdf_amount')) ?>" name="steadfast-amount" class="<?php echo esc_attr($amnt_class); ?>" value="<?php echo esc_attr($input_value); ?>" data-order-id="<?php echo esc_attr($order_id); ?>" style="width: 80px">
		<?php }

        if ($column === 'courier_score') { ?>
			<button id="stdf-courier-score" class="stdf-success-ratio" data-stdf-hover="Refresh" data-courier-score-nonce="<?php echo esc_attr(wp_create_nonce('stdf_courier_score_nonce')) ?>" data-order-id="<?php echo esc_attr($order_id); ?>"><span><?php echo esc_html($success_ratio) ?><span></span></button>
<?php }
        }
}

if (! function_exists('stdf_bulk_send_order')) {
    /**
     * @return mixed|string
     */
    function stdf_bulk_send_order($redirect, $doaction, $object_ids)
    {

        $new_ids = [];

        foreach ($object_ids as $order_id) {
            $is_sent = get_post_meta($order_id, 'steadfast_is_sent', true);
            if (! $is_sent) {
                $new_ids[] = $order_id;
            }
        }

        if ($doaction === 'send_to_steadFast_bulk') {
            if (! empty($new_ids)) {
                foreach ($new_ids as $order_id) {
                    $sent = send_order_to_steadfast_api($order_id);
                    if ($sent == 'success') {
                        update_post_meta($order_id, 'steadfast_is_sent', 'yes');
                    }
                }
            }

            $redirect = add_query_arg(
                [
                    'bulk_action' => 'send_to_steadFast_bulk',
                    'changed' => count($object_ids),
                ],
                $redirect
            );
        }

        return $redirect;
    }
}

if (! function_exists('stdf_customer_courier_score')) {
    function stdf_customer_courier_score($phone_number, $order_id)
    {

        $phone_number = preg_replace('/[^0-9]/', '', $phone_number);
        $url = 'https://portal.packzy.com/api/v1/fraud_check/'.$phone_number;

        $args = [
            'method' => 'GET',
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return 'API Request Error: '.$response->get_error_message();
        }

        $body = wp_remote_retrieve_body($response);
        $order_info = json_decode($body, true);
        $success_ratio = stdf_calculate_success_rate($order_info['total_parcels'], $order_info['total_delivered']);
        update_post_meta($order_id, 'stdf_success_ratio', $success_ratio);

        $order_info['success_ratio'] = $success_ratio;

        return $order_info;
    }
}

if (! function_exists('stdf_check_current_balance')) {
    function stdf_check_current_balance($check)
    {

        if ($check !== 'check-yes') {
            return false;
        }

        $checkbox = get_option('stdf_settings_tab_checkbox', false);
        $api_secret_key = get_option('api_settings_tab_api_secret_key', false);
        $api_key = get_option('api_settings_tab_api_key', false);

        $args = [
            'method' => 'GET',
            'headers' => [
                'content-type' => 'application/json',
                'api-key' => sanitize_text_field($api_key),
                'secret-key' => sanitize_text_field($api_secret_key),
            ],
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'cookies' => [],
        ];
        if ($checkbox == 'yes') {
            $response = wp_remote_get('https://portal.packzy.com/api/v1/get_balance', $args);

            $request = json_decode(wp_remote_retrieve_body($response), true);
            if ($request['status'] == '200') {
                return $request;
            } else {
                return 'unauthorized';
            }
        } else {
            return 'failed';
        }
    }
}

if (! function_exists('stdf_calculate_success_rate')) {
    function stdf_calculate_success_rate($total, $delivered)
    {
        return ($total > 0) ? round(($delivered / max(1, $total)) * 100, 2).'%' : '00.0%';
    }
}
