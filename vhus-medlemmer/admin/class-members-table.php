<?php
/**
 * Member list table.
 *
 * @package VHus
 */

namespace VHus\Admin;

use VHus\Members;
use VHus\Roles;
use VHus\Workshops;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Searchable, filterable member list. Rows are already scoped to what the user may see.
 */
class Members_Table extends \WP_List_Table {

	/** Constructor. */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'medlem',
				'plural'   => 'medlemmer',
				'ajax'     => false,
			)
		);
	}

	/** Columns. */
	public function get_columns() {
		return array(
			'name'      => 'Navn',
			'workshops' => 'Værksted',
			'status'    => 'Status',
			'email'     => 'E-mail',
			'phone'     => 'Telefon',
			'fob'       => 'Brik',
		);
	}

	/** Sortable columns. */
	protected function get_sortable_columns() {
		return array(
			'name'      => array( 'name', false ),
			'workshops' => array( 'workshops', false ),
			'status'    => array( 'status', false ),
			'email'     => array( 'email', false ),
		);
	}

	/** Load rows. */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$rows = Members::query(
			array(
				'search'      => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'workshop_id' => isset( $_REQUEST['workshop'] ) ? absint( $_REQUEST['workshop'] ) : 0,
				'status'      => isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : '',
				'orderby'     => isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'name',
				'order'       => isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'asc',
			)
		);
		// phpcs:enable

		$per_page = 50;
		$page     = $this->get_pagenum();
		$this->set_pagination_args(
			array(
				'total_items' => count( $rows ),
				'per_page'    => $per_page,
			)
		);
		$this->items           = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
	}

	/** Name with edit link. */
	protected function column_name( $item ) {
		$url = add_query_arg(
			array(
				'page'   => 'vhus-members',
				'action' => 'edit',
				'id'     => $item['id'],
			),
			admin_url( 'admin.php' )
		);
		return sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $url ), esc_html( $item['name'] ) );
	}

	/** Status label. */
	protected function column_status( $item ) {
		$label = Members::MEMBER_STATUSES[ $item['status'] ] ?? $item['status'];
		return sprintf( '<span class="vhus-badge vhus-status-%s">%s</span>', esc_attr( $item['status'] ), esc_html( $label ) );
	}

	/** Phone. */
	protected function column_phone( $item ) {
		return esc_html( $item['phone_e164'] );
	}

	/** Other columns. */
	protected function column_default( $item, $column_name ) {
		$v = (string) ( $item[ $column_name ] ?? '' );
		return '' === $v ? '<span aria-hidden="true">—</span>' : esc_html( $v );
	}

	/** Workshop and status filters. */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$cur_ws = isset( $_REQUEST['workshop'] ) ? absint( $_REQUEST['workshop'] ) : 0;
		$cur_st = isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : '';
		// phpcs:enable
		$visible = Roles::visible_workshop_ids();
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="vhus-filter-workshop">Værksted</label>';
		echo '<select name="workshop" id="vhus-filter-workshop"><option value="">Alle værksteder</option>';
		foreach ( Workshops::all() as $w ) {
			if ( is_array( $visible ) && ! in_array( (int) $w->id, $visible, true ) ) {
				continue;
			}
			printf( '<option value="%d"%s>%s</option>', (int) $w->id, selected( $cur_ws, (int) $w->id, false ), esc_html( $w->name ) );
		}
		echo '</select>';
		echo '<label class="screen-reader-text" for="vhus-filter-status">Status</label>';
		echo '<select name="status" id="vhus-filter-status"><option value="">Alle statusser</option>';
		foreach ( Members::MEMBER_STATUSES as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $cur_st, $k, false ), esc_html( $label ) );
		}
		echo '</select>';
		submit_button( 'Filtrér', '', 'filter_action', false );
		echo '</div>';
	}

	/** Empty state. */
	public function no_items() {
		echo 'Ingen medlemmer fundet.';
	}
}
