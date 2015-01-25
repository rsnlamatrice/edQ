<?php 
/* Heures travaillées par contexte
Les paramètres de filtres commencent par f-- suivi du nom du champ
*/

$db = get_db();
/* sélection des timeslots et de leur membre le plus profond */
$sql = "SELECT ts.*, tsm.id AS member_id, tsm.name AS member_name
FROM `fo_timeslots` `ts`
JOIN `fo_objects` `tso`
	ON `tso`.`id` = ts.`object_id`
JOIN (
	/* profondeur maxi des membres de l'objet timeslot */
	SELECT tsom.object_id, MAX(mo.depth) AS depth
	FROM `fo_object_members` `tsom` /* membres des timeslot */
	JOIN `fo_members` `mo` /* membres auxquels les timeslot sont rattachés */
		ON `tsom`.`member_id` = `mo`.`id`
	GROUP BY tsom.object_id
) `tsomax`
	ON `tso`.`id` = tsomax.`object_id`
	AND tso.object_type_id = 17
JOIN `fo_object_members` `tsom`
	ON `tsom`.`object_id` = `tsomax`.`object_id`
JOIN `fo_members` `tsm`
	ON `tsom`.`member_id` = tsm.`id`
	AND tsomax.depth = tsm.depth
	AND tsm.dimension_id = 1 /* workspace */
/*JOIN `fo_objects` `tsmo`
	ON `tsmo`.`id` = tsm.`object_id`
	AND tsmo.object_type_id = 1*/
";

/*	$rows = $db->all( $sql . ' LIMIT 0, 200' );

	$args = array('rows' => $rows);
echo count($rows);
	page::call('/_html/table/rows', $args);*/


$sql = "SELECT * FROM (

	SELECT `ou`.object_id AS IdSalarie
	, DATE_FORMAT((`ts`.`start_time`),'%Y-%m') AS `Mois`
	, `ou`.`first_name` AS `Salarié`
	, SUM(TIMESTAMPDIFF(SECOND, `ts`.`start_time`, `ts`.`end_time`))/3600 AS `Heures`
	, SUM(TIMESTAMPDIFF(SECOND, `ts`.`start_time`, `ts`.`end_time`))/3600 * CAST(uv.value AS DECIMAL(9,3)) AS `Valorisation`
	, pr.name AS Contexte
	, IF(pv.value IS NULL OR pv.value = '', 'ERR', pv.value) AS CodeAnalytique
	FROM (" . $sql . ") `ts`
	JOIN `fo_contacts` `ou`
		ON `ou`.`object_id` = `ts`.`contact_id`
	JOIN `fo_members` `pr`
		ON `ts`.`member_id` = `pr`.`id`
	LEFT JOIN fo_member_custom_property_values pv
		ON pv.member_id = pr.id
		AND pv.custom_property_id = ?
	LEFT JOIN fo_object_properties uv
		ON uv.rel_object_id = ou.object_id
		AND uv.name = ?
		
	WHERE (`ts`.`start_time` BETWEEN ? AND ?)
	AND (`ts`.`end_time` <> 0)
	
	GROUP BY `ou`.object_id, date_format((`ts`.`start_time` + interval 1 hour),'%Y-%m')
		, `ou`.`first_name`
		, pr.name, pv.value
	ORDER BY Mois DESC, `Salarié`, CodeAnalytique, Contexte
) a";


//die ('<pre>' . $sql . '</pre>');

	$arguments = array_merge($_REQUEST, isset($arguments) ? $arguments : array());
	
	$where = 0;
	$params = array();

	$custom_property_id = 1; //Id de la propriété CodeAnalytique ajoutée
	$params[] = $custom_property_id;

	$custom_property_id = 'Taux horaire'; //Id de la propriété TauxHoraire ajoutée
	$params[] = $custom_property_id;

	/*$root_id = 0; //Id du parent
	$params[] = $root_id;*/

	$dateDebut = isset($arguments['q--date-debut'])
		? $arguments['q--date-debut']
		: '01/' . date('m/Y');//'01/09/' . ((int)date('m') > 8 ? date('Y') : (int)date('Y') - 1);
	$dateTimeDebut = is_string($dateDebut) ? DateTime::createFromFormat('d/m/Y H:i:s', $dateDebut. '00:00:00') : $dateDebut;
	$params[] = $dateTimeDebut->format('Y-m-d H:i:s');

	$dateFin = isset($arguments['q--date-fin'])
		? $arguments['q--date-fin']
		: '31/08/' . ((int)date('m') <= 8 ? date('Y') : (int)date('Y') + 1);
	$dateTimeFin = is_string($dateFin) ? DateTime::createFromFormat('d/m/Y H:i:s', $dateFin . ' 23:59:59') : $dateFin;
	$params[] = $dateTimeFin->format('Y-m-d H:i:s') ;
	
	$maxRows = isset($arguments['q--limit'])
			? ((int)$arguments['q--limit'])
			: 20;
	$params[] = 0;
	$params[] = $maxRows;
	//var_dump($params);
	//var_dump($sql);
	$rows = $db->all( $sql . ' LIMIT ?, ?', $params );

	if(isset($arguments) && $arguments['node--get'] == 'rows'){
		$arguments['rows'] = $rows;
		return;
	}
$uid = uniqid('form');
?>
<form id="<?=$uid?>" method="POST" action="<?=page::url( $node )?>" autocomplete="off">
<input type="hidden" name="q--limit" value="9999"/>
<table class="edq" style="overflow: scroll;">
	<caption style="text-align: left;"><?= count($rows) . ' ligne' . (count($rows) > 1 ? 's' : '')
		. ( count($rows) == $maxRows ? ' ou plus' : '' )?>
		<br/><?php
		//selecteur de mois
		$dateSel = $dateTimeDebut->format('Y')
			. '|'
			. (($dateTimeDebut->diff($dateTimeFin)->m > 1) ? '0' : $dateTimeDebut->format('m'));
		?><select class="month-selector"><?php
		$firstMonth = 9;
		$thisYear = (int)date('Y');
		$date = strtotime(date('Y-m') . '-01');
		do {
			$year = (int)date('Y', $date);
			$month = (int)date('m', $date);
			$value = date('Y|m', $date);
			?><option value="<?= $value ?>" <?=$dateSel == $value ? 'selected="selected"' : ''?>><?php
				echo htmlspecialchars(strftime('%B %Y', $date));
			?></option><?php
			if( $month == $firstMonth ){
				$value = date('Y|0', $date);
				?><option value="<?= $value ?>" <?=$dateSel == $value ? 'selected="selected"' : ''?>><?php
					echo "Exercice " . ($year) . '/' . ($year + 1);
				?></option><?php
			}
			//mois précédent
			$date = strtotime("-1 month", $date);
			
		} while($thisYear < $year + 3 || $month > $firstMonth);
		?></select>
		du <input size="10" name="q--date-debut" value="<?=$dateDebut?>"/> au <input size="10" name="q--date-fin" value="<?=$dateFin?>"/><?php
		/* submit */
		?><input type="submit" value="Rechercher" style="margin-left: 2em;"/><?php
		/* télécharger */
		$viewer = tree::get_id_by_name('/_Exemples/Convertisseurs/table/csv');
		$viewer_options = "&node=" . $node['id']
				. "&file--name=" . urlencode($node['nm'])
				. "&node--get=html";
		?><a class="file-download" href="view.php?id=<?= $viewer ?><?= $viewer_options ?>&vw=file.call" style="margin-left: 2em;">télécharger</a>
	</caption>
	<thead><tr>
	<th><input id="<?=$uid?>-rows-selector" type="checkbox" checked="checked"/></th>
	<th class="hidden">IdSalarié</th>
	<th>Salarié</th>
	<th>CodeAnalytique</th>
	<th>Contexte</th>
	<th>Mois</th>
	<th>Heures</th>
	<th>Valorisation</th>
	</tr>
	</thead>
	<tbody><?php
	foreach($rows as $row){
		?><tr>
		<td><input type="checkbox" checked="checked"/></td>
		<td class="hidden"><?=$row["IdSalarie"]?></td>
		<td><?=htmlspecialchars( $row["Salarié"] )?></td>
		<td><?=$row["CodeAnalytique"]?></td>
		<td><?=htmlspecialchars( preg_replace('/\\/*$/', '', $row["Contexte"]) )?></td>
		<td><?=$row["Mois"]?></td>
		<td><?=number_format($row["Heures"], 2, ',', '') ?></td>
		<td><?=number_format($row["Valorisation"], 2, ',', '') ?></td>
		</tr><?php
	}
	?></tbody>
	<tfoot><tr><td colspan="99"></tr>
	</tfoot>
</table>
</form>
<style>
	#<?=$uid?> table thead tr.filters th {
		vertical-align : bottom;
	}
	#<?=$uid?> table .hidden {
		display : none;
	}
	#<?=$uid?> table .month-selector {
		margin-right: 1em;
		text-align: right;
	}
	#<?=$uid?> table thead select {
		height: 8em;
	}
	#<?=$uid?> table tbody {
		background-color: white;
	}
	#<?=$uid?> table tbody td:nth-child(8)
	, #<?=$uid?> table tbody td:nth-child(7){
		text-align: right;
	}
	#<?=$uid?> table tbody tr:hover {
		background-color: #FBFBFB;
	}
	#<?=$uid?> table tbody td {
		border: 1px solid #F0F0F0;
	}
</style>
<script>
	function selectionChanged(){
		var rows = "";
		$("#<?=$uid?> tbody input:checked").each(function(){
			rows += ";" + $(this).parents("tr:first").find('.email').text().trim();
		});
		$("#<?=$uid?>-rows").val(rows.substr(1)).select();
	}
	if(typeof padLeft === 'undefined'){
		var padLeft = function  (str, length, fillWith){
			while(str.length < length) str = (fillWith ? fillWith : '0') . str;
			return str;
		}
	}
	$().ready(function(){
		$('#<?=$uid?> tbody input[type="checkbox"]')
			.click( selectionChanged )
		;
		selectionChanged();
		
		$('#<?=$uid?>-rows-selector').click(function(){
			if(this.checked)
				$('#<?=$uid?> tbody input[type="checkbox"]')
					.attr({'checked': 'checked'})
					.each(function(){//force
						this.checked = true;
					})
				;
			else
				$('#<?=$uid?> tbody input[type="checkbox"]').removeAttr('checked');
		});
		
		// La sélection d'un mois affecte les dates de début et fin
		$('#<?=$uid?> .month-selector').change(function(){
			$this = $(this);
			$form = $this.parents('form:first');
			var year = parseInt(this.value.split('|')[0]);
			var month = parseInt(this.value.split('|')[1]);
			var date0 = new Date(year, (month == 0 ? 9 : month) - 1, 1);
			var date1 = new Date(new Date(year + (month == 0 ? 1 : 0), (month == 0 ? 9 : month + 1) - 1, 1) - 1000*3600*24);
			//date1 = new Date(date1.valueOf() - 1000*3600*24);
			var param = 'q--date-debut';
			$form.find(':input[name="' + param + '"]').val(padLeft(date0.getDate(),2) + '/' + padLeft(date0.getMonth() + 1,2) + '/' + date0.getFullYear());
			param = 'q--date-fin';
			$form.find(':input[name="' + param + '"]').val(padLeft(date1.getDate(),2) + '/' + padLeft(date1.getMonth() + 1,2) + '/' + date1.getFullYear());
		});
		
		//au début du click sur le téléchargement, on ajoute q--date-debut et q--date-fin au href
		$('#<?=$uid?> .file-download').mousedown(function(){
			$this = $(this);
			$form = $this.parents('form:first');
			var href = $this.attr('href');
			var params = ['q--date-debut', 'q--date-fin'];
			for(var param in params){
				param = params[param];
				var pos = href.indexOf('&' + param + '=');
				var value = $form.find(':input[name="' + param + '"]').val();
				if(pos > 0){
					var posNext = href.indexOf('&', pos + 2);
					href = href.substr(0, pos)
						+ '&' + param + '=' + value
						+ (posNext > 0 ? href.substr(posNext) : '')
					;
				}
				else
					href += '&' + param + '=' + value;
			}
			$this.attr('href', href);
		});
	});
</script>
<?= isset($view) ? $view->searchScript($uid) : '$view no set'?>