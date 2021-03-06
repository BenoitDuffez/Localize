<?php

require_once('UI.php');

class UI_Table extends UI {

    const OPTIMAL_ROWS_PER_PAGE = 20;
    const MAX_PAGE_COUNT = 14;

    protected $columnCount;
    /**
     * Column data for this table's head (one array entry per column in the header row)
     *
     * @var array
     */
    protected $headers;
    /**
     * Row data for this table's body
     *
     * The first level contains the single rows for each page
     *
     * The second level contains the columns for each row
     *
     * @var array|UI_Table_Row[]
     */
    protected $rows;
    /**
     * Contains priorities for the columns' width values (number of grid cells per column)
     *
     * @var array
     */
    protected $columnPriorities;
    protected $uniqueViewID;

    function __construct($headers) {
        if (isset($headers) && is_array($headers) && !empty($headers)) {
            $this->columnCount = count($headers);
            $this->headers = $headers;
        }
        else {
            throw new Exception('Headers must be a non-empty array of strings');
        }
        $this->rows = array();
        $this->columnPriorities = array();
        $this->uniqueViewID = sha1('table-'.mt_rand(100000, 900000));
    }

    public function addRow($columns, $id = '', $cssClasses = '', $cssStyles = '') {
        if (isset($columns) && is_array($columns) && !empty($columns)) {
            if (count($columns) == $this->columnCount) {
                $this->rows[] = new UI_Table_Row($columns, $id, $cssClasses, $cssStyles, $this->columnPriorities);
            }
            else {
                throw new Exception('Row must have '.$this->columnCount.' columns as specified by the headers');
            }
        }
        else {
            throw new Exception('Columns must be a non-empty array of strings');
        }
    }

    public function getHTML() {
        $out = '<div class="table-responsive">';
        $out .= '<table class="table table-bordered" id="'.$this->uniqueViewID.'">';
        $headHTML = '<thead><tr>';
        $hasHeaders = false;
        $counter = 0;
        foreach ($this->headers as $header) {
            $headHTML .= '<th'.(isset($this->columnPriorities[$counter]) ? ' class="col-lg-'.$this->columnPriorities[$counter].'"' : '').'>'.$header.'</th>';
            if ($header != '') {
                $hasHeaders = true;
            }
            $counter++;
        }
        $headHTML .= '</tr></thead>';
        if ($hasHeaders) {
            $out .= $headHTML;
        }

        $pages = array();
        $rowCount = count($this->rows);
        $rowsPerPage = self::getOptimalRowsPerPageCount($rowCount);
        foreach ($this->rows as $rowID => $row) {
            $page = (int) ($rowID / $rowsPerPage);
            if (!isset($pages[$page])) {
                $pages[$page] = '';
            }
            $pages[$page] .= $row->getHTML();
        }
        foreach ($pages as $pageID => $pageContent) {
            $out .= '<tbody class="table-page table-page-'.$pageID.'"';
            if ($pageID > 0) {
                $out .= ' style="display:none;"';
            }
            $out .= '>'.$pageContent.'</tbody>';
        }
        $out .= '</table>';
        $out .= '</div>';

        $pageCount = count($pages);
        if ($pageCount > 1) {
            $out .= '<div class="text-center"><ul class="pagination pagination-lg" id="pagination-'.$this->uniqueViewID.'">';
            for ($p = 1; $p <= $pageCount; $p++) {
                $out .= '<li';
                if ($p == 1) {
                    $out .= ' class="active"';
                }
                $out .= '><a href="#" onclick="openTablePage(\''.$this->uniqueViewID.'\', '.($p-1).'); return false;">'.$p.'</a></li>';
            }
            $out .= '</ul></div>';
        }
        return $out;
    }

    public function setColumnPriorities() {
        $varargs = func_get_args();
        $this->columnPriorities = $varargs;
    }

    protected static function getOptimalRowsPerPageCount($rowCount) {
        $optimalCasePages = (int) ceil($rowCount / self::OPTIMAL_ROWS_PER_PAGE);
        if ($optimalCasePages <= self::MAX_PAGE_COUNT) {
            return self::OPTIMAL_ROWS_PER_PAGE;
        }
        else {
            $requiredRowsPerPage = (int) ceil($rowCount / self::MAX_PAGE_COUNT);
            return $requiredRowsPerPage;
        }
    }

}

class UI_Table_Row extends UI {

    protected $columns;
    protected $id;
    protected $cssClasses;
    protected $cssStyles;
    protected $columnPriorities;

    public function __construct($columns, $id = '', $cssClasses = '', $cssStyles = '', $columnPriorities = array()) {
        $this->columns = $columns;
        $this->id = $id;
        $this->cssClasses = $cssClasses;
        $this->cssStyles = $cssStyles;
        $this->columnPriorities = $columnPriorities;
    }

    public function getHTML() {
        $out = '<tr';
        if (!empty($this->id)) {
            $out .= ' id="'.$this->id.'"';
        }
        if (!empty($this->cssClasses)) {
            $out .= ' class="'.$this->cssClasses.'"';
        }
        if (!empty($this->cssStyles)) {
            $out .= ' style="'.$this->cssStyles.'"';
        }
        $out .= '>';
        $counter = 0;
        foreach ($this->columns as $column) {
            $out .= '<td'.(isset($this->columnPriorities[$counter]) ? ' class="col-lg-'.$this->columnPriorities[$counter].'"' : '').'>'.$column.'</td>';
            $counter++;
        }
        $out .= '</tr>';
        return $out;
    }

}