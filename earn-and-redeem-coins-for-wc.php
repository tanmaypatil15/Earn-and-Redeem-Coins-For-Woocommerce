<?php
/*
* Plugin Name: Earn and Redeem Coins For Woocommerce
* Description: Earn and Redeem Coins API for WooCommerce Rewards and Points using GET & POST methods.
* Version: 1.0
* Author: Tanmay Patil
*/

add_action('rest_api_init', 'register_custom_points_route');

function register_custom_points_route() {
    register_rest_route(
        'wc/v3',
        '/points-and-rewards',
        array(
            'methods'  => array('GET', 'POST'),
            'callback' => 'handle_points_request',
            'args'     => array(
                'user_email' => array(
                    'description' => 'User Email for whom to retrieve points.',
                    'type'        => 'string',
                    'required'    => false,
                ),
                'user_id'    => array(
                    'description' => 'User ID for whom to retrieve points.',
                    'type'        => 'integer',
                    'required'    => false,
                ),
                'action'     => array(
                    'description' => 'Action to perform (update/redeem).',
                    'type'        => 'string',
                    'required'    => false,
                    'enum'        => array('update', 'redeem'),
                ),
                'points'     => array(
                    'description' => 'Number of points to update.',
                    'type'        => 'integer',
                    'required'    => false,
                ),
//                 'points_balance'  => array(
//                     'description' => 'Number of points_balance to update/redeem.',
//                     'type'        => 'integer',
//                     'required'    => false,
//                 ),
// 				'redeem_points'  => array(
//                     'description' => 'Number of points_balance to redeem.',
//                     'type'        => 'integer',
//                     'required'    => false,
//                 ),
            ),
        )
    );
}

function handle_points_request($request) {
    if ($request->get_method() === 'POST') {
        return handle_points_post_request($request);
    }

    $user_id    = $request->get_param('user_id');
    $user_email = $request->get_param('user_email');

    if (empty($user_id) && !empty($user_email)) {
        $user_id = get_user_id_by_email($user_email);
    }

    if (empty($user_id)) {
        return new WP_REST_Response(array('error' => 'Missing or invalid user_id parameter'), 400);
    }

    $user_data = get_user_data_by_id($user_id);

    $response_data = array(
        'message'         => 'GET request processed successfully',
        'user_id'         => $user_id,
        'user_email'      => $user_data->user_email,
        'points'          => $user_data->points,
        'points_balance'  => $user_data->points_balance,
        'order_id'        => $user_data->order_id,
    );

    return new WP_REST_Response($response_data, 200);
}


function handle_points_post_request($request) {
    $data   = $request->get_json_params();
    $action = $data['action'];
    $points = $data['points'];
    $points_balance = $data['points'];
	$points_to_redeem = $data['points'];
	
    //var_dump($points_balance);
    // Validate action
    if (!in_array($action, array('update', 'redeem'))) {
        return new WP_REST_Response(array('error' => 'Invalid action'), 400);
    }

    // Validate points
    // if (!is_numeric($points) || $points <= 0) {
    //     return new WP_REST_Response(array('error' => 'Invalid points value'), 400);
    // }

    $user_id = $data['user_id'];
    $user_email = $data['user_email'];

    //var_dump($user_id);
    //var_dump($user_email);

    if (empty($user_id)) {
        return new WP_REST_Response(array('error' => 'Missing user_id or user_email parameter'), 400);
    }

    if ($action === 'redeem') {
        // Check if the user has enough points to redeem
        $user_data = get_user_data_by_id($user_id);
        //var_dump($user_data->points_balance);
        if ($user_data->points_balance < $points_to_redeem) {

            return new WP_REST_Response(array('error' => 'Insufficient points balance'), 400);
        }

        // Redeem points
        redeem_user_points($user_id, $points_to_redeem);
		
		$total_data = get_user_points_and_balance($user_id);

		$response_data = array(
			'message'         => 'POST request processed successfully',
			'user_id'         => $user_id,
			'redeem_points'  => $total_data->points_balance,
		);
		
    } 
    
    elseif ($action === 'update') {
        // Update points
        
		if (!$user_id) {
			return new WP_REST_Response(array('error' => 'User not found'), 404);
		}

		// Get user's current points and points balance
		$total_data = get_user_points_and_balance($user_id);

		// Calculate cumulative points and points balance
		$cumulative_points = $total_data->points;
		$cumulative_points_balance = $total_data->points_balance + $points;


		// Update points and points_balance in the database
		update_user_points($user_id, $cumulative_points, $cumulative_points_balance);
		

		$response_data = array(
			'message'         => 'POST request processed successfully',
			'user_id'         => $user_id,
			'points_balance'  => $cumulative_points_balance,
		);
    }

    return new WP_REST_Response($response_data, 200);
}


function update_user_points($user_id, $points, $points_balance) {
    global $wpdb;

    
    $points_table = $wpdb->prefix . 'wc_points_rewards_user_points';

    // Check if the user already has a record in the points table
    $existing_record = $wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM $points_table WHERE user_id = %d", $user_id));
    //var_dump($existing_record);
    if ($existing_record) {
		//var_dump($existing_record);
		
        // Update the existing record
        $wpdb->update(
            $points_table,
            array('points' => $points, 'points_balance' => $points_balance),
            array('id' => $existing_record)
        );
     } 
    else {
        // Insert a new record if the user doesn't have one
        $wpdb->insert(
            $points_table,
            array('user_id' => $user_id, 'points' => $points, 'points_balance' => $points_balance)
        );
    }
}





function redeem_user_points($user_id, $points_to_redeem) {
    global $wpdb;

    $points_table = $wpdb->prefix . 'wc_points_rewards_user_points';

    // Check if the user has enough points to redeem
    $user_data = get_user_points_and_balance($user_id);
    if ($user_data->points_balance < $points_to_redeem) {
        return; // Not enough points to redeem
    }

    // Update points_balance by subtracting redeemed points
    $new_balance = $user_data->points_balance - $points_to_redeem;

    // Update points_balance in the database
    $wpdb->update(
        $points_table,
        array('points_balance' => $new_balance),
        array('user_id' => $user_id)
    );
}

//Getting User data by User ID
function get_user_data_by_id($user_id) {
    global $wpdb;

    $user_table = $wpdb->prefix . 'users';
    $points_table = $wpdb->prefix . 'wc_points_rewards_user_points';

    $query = $wpdb->prepare(
        "SELECT u.user_email, p.points_balance, p.points, p.order_id
        FROM $user_table AS u
        LEFT JOIN $points_table AS p ON u.ID = p.user_id
        WHERE u.ID = %d",
        $user_id
    );

    return $wpdb->get_row($query);
}

//Getting User data by Email ID
function get_user_data_by_email($user_email) {
    global $wpdb;

    $user_table = $wpdb->prefix . 'users';
    $points_table = $wpdb->prefix . 'wc_points_rewards_user_points';

    $query = $wpdb->prepare(
        "SELECT u.user_email, p.points_balance, p.points, p.order_id
        FROM $user_table AS u
        LEFT JOIN $points_table AS p ON u.ID = p.user_id
        WHERE u.user_email = %s",
        $user_email
    );

    return $wpdb->get_row($query);
}

function get_user_points_and_balance($user_id) {
    global $wpdb;

    $points_table = $wpdb->prefix . 'wc_points_rewards_user_points';
	
    // Prepare and execute the SQL query
    $query = $wpdb->prepare(
        "SELECT SUM(points) as points, SUM(points_balance) as points_balance
        FROM $points_table
        WHERE user_id = %d",
        $user_id
    );

    $user_data = $wpdb->get_row($query);

    // If the user doesn't have a record, return default values
    if (!$user_data) {
        return (object) array(
            'points' => 0,
            'points_balance' => 0,
        );
    }

    return $user_data;
}
