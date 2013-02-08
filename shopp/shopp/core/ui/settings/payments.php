<div class="wrap shopp">
	<?php if (!empty($updated)): ?><div id="message" class="updated fade"><p><?php echo $updated; ?></p></div><?php endif; ?>

	<div class="icon32"></div>
	<h2><?php _e('Payment Settings','Shopp'); ?></h2>

<form action="<?php echo $this->url; ?>" id="payments" method="post">
<div>
	<?php wp_nonce_field('shopp-settings-payments'); ?>
</div>

<div class="tablenav"><div class=" actions">
	<select name="id" id="payment-option-menu">
	<option><?php _e('Add a payment system&hellip;','Shopp'); ?></option>
	<?php echo menuoptions($installed,false,true); ?>
	</select>
	<button type="submit" name="add-payment-option" id="add-payment-option" class="button-secondary hide-if-js" tabindex="9999"><?php _e('Add Payment Option','Shopp'); ?></button>
	</div>
</div>

<table class="widefat" cellspacing="0">
	<thead>
	<tr><?php print_column_headers('shopp_page_shopp-settings-payments'); ?></tr>
	</thead>
	<tfoot>
	<tr><?php print_column_headers('shopp_page_shopp-settings-payments',false); ?></tr>
	</tfoot>
	<tbody id="payments-settings-table" class="list">
	<?php

		if ($edit && !in_array($edit,$gateways)) {
			$template_data = array(
				'${cancel_href}' => $this->url,
				'${instance}' => $id
			);
			$editor = str_replace(array_keys($template_data),$template_data,$editor);
			$editor = preg_replace('/\${\w+}/','',$editor);

			echo $editor;
		}

		if (count($gateways) == 0 && !$edit): ?>
			<tr id="no-payment-settings"><td colspan="6"><?php _e('No payment methods, yet.','Shopp'); ?></td></tr>
		<?php
		endif;

		$hidden = get_hidden_columns('shopp_page_shopp-settings-payments');
		$event = false;
		$even = false;
		foreach ($gateways as $gateway):
			$id = false;
			if (false !== strpos($gateway,'-')) list($gateway,$id) = explode('-',$gateway);

			if (!isset($Gateways->active[$gateway])) continue;
			$Gateway = $Gateways->active[$gateway];
			$payment = $Gateway->settings;

			if (false !== $id) {
				$payment = $Gateway->settings[$id];
				$slug = join('-',array($gateway,$id));
			} else $slug = $gateway;

			$cards = array();
			if (isset($payment['cards'])) {
				foreach ((array)$payment['cards'] as $symbol) {
					$Paycard = Lookup::paycard($symbol);
					if ($Paycard) $cards[] = $Paycard->name;
				}
			}

			$editurl = add_query_arg(array('id'=>$slug),$this->url);
			$deleteurl = wp_nonce_url(add_query_arg(array('delete'=>$slug),$this->url),'shopp_delete_gateway');

			$classes = array();
			if (!$even) $classes[] = 'alternate'; $even = !$even;

			if ($edit && $edit == $slug && in_array($edit,$gateways)) {
				$event = strtolower($edit);

				$template_data = array(
					'${editing_class}' => "$event-editing",
					'${cancel_href}' => $this->url,
					'${instance}' => $id
				);
				// Handle payment data value substitution for multi-instance payment systems
				foreach ($payment as $name => $value)
					$template_data['${'.$name.'}'] = $value;
				$editor = str_replace(array_keys($template_data),$template_data,$editor);
				$editor = preg_replace('/\${\w+}/','',$editor);

				echo $editor;
				if ( $edit == $slug ) continue;
			}

			$label = empty($payment['label'])?__('(no label)','Shopp'):$payment['label'];

		?>
	<tr class="<?php echo join(' ',$classes); ?>" id="payment-setting-<?php echo sanitize_title_with_dashes($gateway); ?>">
		<td class="name column-name"><a class="row-title" href="<?php echo $editurl; ?>" title="<?php _e('Edit','Shopp'); ?> &quot;<?php echo esc_attr($label); ?>&quot;" class="edit"><?php echo esc_html($label); ?></a>
			<div class="row-actions">
				<span class='edit'><a href="<?php echo esc_url($editurl); ?>" title="<?php _e('Edit','Shopp'); ?> &quot;<?php echo esc_attr($label); ?>&quot;" class="edit"><?php _e('Edit','Shopp'); ?></a> | </span><span class='delete'><a href="<?php echo esc_url($deleteurl); ?>" title="<?php _e('Delete','Shopp'); ?> &quot;<?php echo esc_attr($label); ?>&quot;" class="delete"><?php _e('Delete','Shopp'); ?></a></span>
			</div>
		</td>
		<?php // @todo Add title hover labels for accessibility/instructions ?>
		<td class="processor column-processor"><?php echo esc_html($Gateway->name); ?></td>
		<td class="supported column-supported"><?php echo join(', ',$cards); ?></td>
		<td class="ssl column-ssl">
			<div class="checkbox"><?php if ($Gateway->secure): ?><div class="checked">&nbsp;</div><?php else: ?>&nbsp;<?php endif; ?></div>
		</td>
		<td class="captures column-captures">
			<div class="checkbox"><?php if ($Gateway->captures): ?><div class="checked">&nbsp;</div><?php else: ?>&nbsp;<?php endif; ?></div>
		</td>
		<td class="recurring column-recurring">
			<div class="checkbox"><?php if ($Gateway->recurring): ?><div class="checked">&nbsp;</div><?php else: ?>&nbsp;<?php endif; ?></div>
		</td>
		<td class="refunds column-refunds">
			<div class="checkbox"><?php if ($Gateway->refunds): ?><div class="checked">&nbsp;</div><?php else: ?>&nbsp;<?php endif; ?></div>
		</td>
	</tr>
	<?php endforeach; ?>
	</tbody>
</table>

</form>

</div>

<?php do_action('shopp_gateway_module_settings'); ?>

<script type="text/javascript">
/* <![CDATA[ */
var gateways = <?php echo json_encode(array_map('sanitize_title_with_dashes',array_keys($installed))); ?>;
<?php if ($event): ?>jQuery(document).ready(function($) { $(document).trigger('<?php echo $event; ?>Settings',[$('#payments-settings-table tr.<?php echo $event; ?>-editing')]); });<?php endif; ?>

/* ]]> */
</script>