<?php defined( 'WPINC' ) || exit ; ?>
<?php

$menu_list = array(
	'summary'				=> __( 'Summary', 'litespeed-cache' ),
	'settings-general'		=> __( 'General Settings', 'litespeed-cache' ),
	'settings-simulation'	=> __( 'Simulation Settings', 'litespeed-cache' ),
	'settings-sitemap'		=> __( 'Sitemap Settings', 'litespeed-cache' ),
) ;

?>

<div class="wrap">
	<h1 class="litespeed-h1">
		<?php echo __( 'LiteSpeed Cache Crawler', 'litespeed-cache' ) ; ?>
	</h1>
	<span class="litespeed-desc">
		v<?php echo LiteSpeed_Cache::PLUGIN_VERSION ; ?>
	</span>
	<hr class="wp-header-end">
</div>

<div class="litespeed-wrap">
	<h2 class="litespeed-header">
	<?php
		$i = 1 ;
		foreach ($menu_list as $tab => $val){
			$accesskey = $i <= 9 ? "litespeed-accesskey='$i'" : '' ;
			echo "<a class='litespeed-tab' href='#$tab' data-litespeed-tab='$tab' $accesskey>$val</a>" ;
			$i ++ ;
		}
	?>
	</h2>

	<div class="litespeed-body">
	<?php

		// include all tpl for faster UE
		foreach ($menu_list as $tab => $val) {
			echo "<div data-litespeed-layout='$tab'>" ;
			require LSCWP_DIR . "admin/tpl/crawler/$tab.tpl.php" ;
			echo "</div>" ;
		}

	?>
	</div>

</div>

<iframe name="litespeedHiddenIframe" src="" width="0" height="0" frameborder="0"></iframe>
