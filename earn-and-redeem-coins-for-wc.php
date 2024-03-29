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
            ),
        )
    );
}

function get_user_id_by_email($user_email) {
    $user = get_user_by('email', $user_email);
    if ($user) {
        return $user->ID;
		
    } else {
        return 0; // Return 0 if the user is not found
    }
}


function get_user_email_by_id($user_id) {
    $user = get_user_by('ID', $user_id);
    if ($user) {
        return $user->user_email;
    } else {
        return 0; // Return 0 if the user is not found
    }
}


function handle_points_request($request) {
    if ($request->get_method() === 'POST') {
        return handle_points_post_request($request);
    }

    $user_id    = $request->get_param('user_id');
    $user_email = get_user_email_by_id($user_id);


    if (empty($user_id)) {
        return new WP_REST_Response(array('error' => 'Missing or invalid user_id parameter'), 400);
    }

    $user_data = get_user_data_by_id($user_id);

    $response_data = array(
        'message'         => 'GET request processed successfully',
        'user_id'         => $user_id,
        'user_email'      => $user_email,
        'overall_points'          => $user_data->total_points,
        'points_balance'  => $user_data->total_points_balance,
    );

    return new WP_REST_Response($response_data, 200);
}


function handle_points_post_request($request) {
    $data   = $request->get_json_params();

    $user_id = $data['user_id'];
    $action = $data['action'];

    //These points will be used for updating your database.
    $points = $data['points'];
    $points_balance = $data['points'];

    //These points will be used for redeeming from your database.
	$points_to_redeem = $data['points'];


    // Validate action
    if (!in_array($action, array('update', 'redeem'))) {
        return new WP_REST_Response(array('error' => 'Invalid action'), 400);
    }

    //Validate points
    if (!is_numeric($points) || $points <= 0) {
        return new WP_REST_Response(array('error' => 'Invalid points value'), 400);
    }


    if (empty($user_id)) {
        return new WP_REST_Response(array('error' => 'Missing user_id or user_email parameter'), 400);
    }

    if ($action === 'redeem') {
        $user_data = get_user_data_by_id($user_id);

        // Check if the user has enough points to redeem
        if ($user_data->points_balance < $points_to_redeem) {
            return new WP_REST_Response(array('error' => 'Insufficient points balance'), 400);
        }

        // Redeem points
        redeem_user_points($user_id, $points_to_redeem);
		
        // Fetching total user_points and user_balance.
		$total_data = get_user_points_and_balance($user_id);

		$response_data = array(
			'message'         => 'POST request processed successfully',
			'user_id'         => $user_id,
			'overall_points'  => $total_data->points,
            'points_balance'  => $total_data->points_balance,
		);
		
    } 
    // Update points
    elseif ($action === 'update') {
        
		if (!$user_id) {
			return new WP_REST_Response(array('error' => 'User not found'), 404);
		}

        // Update points and points_balance in the database
		update_user_points($user_id, $points, $points_balance);

		// Get the user's current points and points balance
		$total_data = get_user_points_and_balance($user_id);

		// Calculate cumulative points and points balance
		$cumulative_points = $total_data->points;
		$cumulative_points_balance = $total_data->points_balance;


		$response_data = array(
			'message'         => 'POST request processed successfully',
			'user_id'         => $user_id,
            'overall_points'  => $cumulative_points,
			'points_balance'  => $cumulative_points_balance,
		);
    }

    return new WP_REST_Response($response_data, 200);
}


function update_user_points($user_id, $points, $points_balance) {
    global $wpdb;
    //exit;
    
    $points_table = $wpdb->prefix . 'wc_points_rewards_user_points';

    // Insert a new record if the user doesn't have one
    $wpdb->insert(
        $points_table,
        array('user_id' => $user_id, 'points' => $points, 'points_balance' => $points_balance)
    );
    
}



function redeem_user_points($user_id, $points_to_redeem) {
    global $wpdb;

    $points_table = $wpdb->prefix . 'wc_points_rewards_user_points';

    while ($points_to_redeem > 0) {

        // Fetch the oldest row for the user based on row ID
        $oldest_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $points_table WHERE user_id = %d AND points_balance > 0 ORDER BY id ASC LIMIT 1",
                $user_id
            )
        );

        if (!$oldest_row) {
            return false; // No more points to redeem
        }

        // Determine the points to be redeemed from this row
        $redeem_from_this_row = min($points_to_redeem, $oldest_row->points_balance);

        // Update the points balance in the oldest row
        $new_balance = $oldest_row->points_balance - $redeem_from_this_row;
        $wpdb->update(
            $points_table,
            array('points_balance' => $new_balance),
            array('id' => $oldest_row->id)
        );

        // Update the total points redeemed
        $points_to_redeem -= $redeem_from_this_row;
    }

    return true; // Points redeemed successfully
}


//Getting User data by User ID
function get_user_data_by_id($user_id) {
    global $wpdb;

    //$user_table = $wpdb->prefix . 'users';
    $points_table = $wpdb->prefix . 'wc_points_rewards_user_points';

    $query = $wpdb->prepare(
        "SELECT *, (SELECT SUM(points_balance) FROM $points_table WHERE user_id = %d) AS total_points_balance, (SELECT SUM(points) FROM $points_table WHERE user_id = %d) AS total_points
        FROM $points_table WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id,
        $user_id, $user_id
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
