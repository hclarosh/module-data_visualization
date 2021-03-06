<?php

namespace FormTools\Modules\DataVisualization;

use FormTools\Core;
use FormTools\Forms;
use FormTools\General as CoreGeneral;
use FormTools\Views;
use PDO, Exception;


class General
{

    /**
     * This returns all visualizations flagged to show up in the quicklinks dialog window.
     *
     * @param $view_id
     */
    public static function getQuicklinkVisualizations($form_id, $view_id)
    {
        $db = Core::$db;
        $account_type = Core::$user->getAccountType();
        $account_id = Core::$user->getAccountId();

        $private_client_accessible_vis_ids = array();
        if ($account_type == "client") {
            $db->query("
                SELECT vis_id
                FROM   {PREFIX}module_data_visualization_clients
                WHERE  account_id = :account_id
            ");
            $db->bind("account_id", $account_id);
            $db->execute();

            $private_client_accessible_vis_ids = $db->fetchAll(PDO::FETCH_COLUMN);
        }

        $visualizations = Visualizations::searchVisualizations(array(
            "form_id"      => $form_id,
            "view_id"      => $view_id,
            "account_type" => $account_type
        ));

        $accessible_visualizations = array();
        foreach ($visualizations as $vis_info) {
            if ($vis_info["access_type"] == "public") {
                $accessible_visualizations[] = $vis_info["vis_id"];
            } else {
                if ($account_type == "client") {
                    if ($vis_info["access_type"] != "admin" && in_array($vis_info["export_group_id"], $private_client_accessible_vis_ids)) {
                        $accessible_visualizations[] = $vis_info["vis_id"];
                    }
                } else {
                    $accessible_visualizations[] = $vis_info["vis_id"];
                }
            }
        }

        return $accessible_visualizations;
    }


    /**
     * This function returns a string of JS containing the list of forms and form Views in the page_ns
     * namespace.
     *
     * Its tightly coupled with the calling page, which is kind of crumby; but it can be refactored later
     * as the need arises.
     */
    public static function getFormViewMappingJs()
    {
        $forms = Forms::getForms();

        $js_rows = array();
        $js_rows[] = "var page_ns = {}";
        $js_rows[] = "page_ns.forms = []";
        $views_js_rows = array("page_ns.form_views = []");

        // convert ALL form and View info into Javascript, for use in the page
        foreach ($forms as $form_info) {
            // ignore those forms that aren't set up
            if ($form_info["is_complete"] == "no") {
                continue;
            }

            $form_id = $form_info["form_id"];
            $form_name = htmlspecialchars($form_info["form_name"]);
            $js_rows[] = "page_ns.forms.push([$form_id, \"$form_name\"])";

            $form_views = Views::getViews($form_id);

            $v = array();
            foreach ($form_views["results"] as $form_view) {
                $view_id   = $form_view["view_id"];
                $view_name = htmlspecialchars($form_view["view_name"]);
                $v[] = "[$view_id, \"$view_name\"]";
            }
            $views = join(",", $v);

            $views_js_rows[] = "page_ns.form_views.push([$form_id,[$views]])";
        }

        $js = array_merge($js_rows, $views_js_rows);
        $js = join(";\n", $js);

        return $js;
    }


    /**
     * This hook gets fired any time the administrator deletes a form. It automatically deletes all assigned visualizations.
     *
     * @param array $info
     */
    public static function deleteFormHook($info)
    {
        $db = Core::$db;

        $form_id = $info["form_id"];
        if (empty($form_id) || !is_numeric($form_id)) {
            return;
        }

        $db->query("
            SELECT vis_id
            FROM   {PREFIX}module_data_visualizations
            WHERE  form_id = :form_id
        ");
        $db->bind("form_id", $form_id);
        $db->execute();

        $vis_ids = $db->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($vis_ids)) {
            $vis_id_str = implode(",", $vis_ids);
            $db->query("DELETE FROM {PREFIX}module_data_visualization_clients WHERE vis_id IN ($vis_id_str)");
            $db->execute();

            $db->query("DELETE FROM {PREFIX}module_data_visualization_cache WHERE vis_id IN ($vis_id_str)");
            $db->execute();

            $db->query("DELETE FROM {PREFIX}module_data_visualizations WHERE vis_id IN ($vis_id_str)");
            $db->execute();
        }
    }


    /**
     * Helper function used wherever the quicklinks dialog is being used. It outputs all required language strings in a
     * g.vis_messages namespace. The assumption is that it's being output in a <script> block and the g object has been
     * defined.
     *
     * @param array $L the contents of the Data Visualization language file. This is passed as a param because it's not
     *     defined as a global outside of the module.
     */
    public static function getVisMessages($L)
    {
        $LANG = Core::$L;

        $js =<<< END
g.vis_messages = {};
g.vis_messages.word_visualizations = "{$L["word_visualizations"]}";
g.vis_messages.word_close = "{$LANG["word_close"]}";
g.vis_messages.phrase_manage_visualizations = "{$L["phrase_manage_visualizations"]}";
g.vis_messages.word_visualizations = "{$L["word_visualizations"]}";
g.vis_messages.phrase_edit_visualization = "{$L["phrase_edit_visualization"]}";
g.vis_messages.phrase_prev_arrow = "{$L["phrase_prev_arrow"]}";
g.vis_messages.phrase_next_arrow = "{$L["phrase_next_arrow"]}";
g.vis_messages.phrase_back_to_vis_list = "{$L["phrase_back_to_vis_list"]}";
g.vis_messages.phrase_last_cached_c = "{$L["phrase_last_cached_c"]}";
g.vis_messages.phrase_not_cached = "{$L["phrase_not_cached"]}";
END;

        return $js;
    }


    public static function updateVisualizationCache($vis_id, $data)
    {
        $db = Core::$db;

        try {
            $db->query("
                DELETE FROM {PREFIX}module_data_visualization_cache
                WHERE vis_id = :vis_id
            ");
            $db->bind("vis_id", $vis_id);
            $db->execute();

            $data = serialize($data);

            $db->query("
                INSERT INTO {PREFIX}module_data_visualization_cache (vis_id, last_cached, data)
                VALUES (:vis_id, :last_cached, :data)
            ");
            $db->bindAll(array(
                "vis_id" => $vis_id,
                "last_cached" => CoreGeneral::getCurrentDatetime(),
                "data" => $data
            ));
            $db->execute();
        } catch (Exception $e) {
            // TODO
        }
    }
}
