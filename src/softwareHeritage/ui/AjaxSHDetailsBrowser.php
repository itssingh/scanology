<?php
/***********************************************************
Copyright (C) 2019
Author: Sandip Kumar Bhuyan<sandipbhyan@gmail.com>

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/
use Fossology\Lib\Auth\Auth;
use Fossology\Lib\BusinessRules\LicenseMap;
use Fossology\Lib\Dao\AgentDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\SoftwareHeritageDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Plugin\DefaultPlugin;
use Fossology\Lib\Proxy\ScanJobProxy;
use Fossology\Lib\Proxy\UploadTreeProxy;
use Symfony\Component\HttpFoundation\JsonResponse;
use \Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AjaxSHDetailsBrowser extends DefaultPlugin
{
    const NAME = "ajax_sh_browser";

    private $uploadtree_tablename = "";
    /** @var UploadDao */
    private $uploadDao;
    /** @var LicenseDao */
    private $licenseDao;
    /** @var AgentDao */
    private $agentDao;
    /**
     * @var SoftwareHeritageDao $shDao
     * SoftwareHeritageDao object
     */
    private $shDao;
    /**
     * configuraiton for software heritage api
     * @var array $configuration
     */
    private $configuration;

    protected $agentNames = array('softwareHeritage' => 'SH');

    public function __construct()
    {
        parent::__construct(self::NAME, array(
            self::TITLE => _("Ajax: File Browser"),
            self::DEPENDENCIES => array("fileBrowse"),
            self::PERMISSION => Auth::PERM_READ,
            self::REQUIRES_LOGIN => false
        ));

        $this->uploadDao = $this->getObject('dao.upload');
        $this->licenseDao = $this->getObject('dao.license');
        $this->agentDao = $this->getObject('dao.agent');
        $this->shDao = $this->container->get('dao.softwareHeritage');
        $this->configuration = parse_ini_file(__DIR__ . '/../agent/softwareHeritage.conf');
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request)
    {
        $upload = intval($request->get("upload"));
        $groupId = Auth::getGroupId();
        if (!$this->uploadDao->isAccessible($upload, $groupId)) {
            throw new \Exception("Permission Denied");
        }

        $item = intval($request->get("item"));
        $this->uploadtree_tablename = $this->uploadDao->getUploadtreeTableName($upload);
        $itemTreeBounds = $this->uploadDao->getItemTreeBounds($item, $this->uploadtree_tablename);
        $left = $itemTreeBounds->getLeft();
        if (empty($left)) {
            throw new \Exception("Job unpack/adj2nest hasn't completed.");
        }

        $scannerAgents = array_keys($this->agentNames);
        $scanJobProxy = new ScanJobProxy($this->agentDao, $upload);
        $scanJobProxy->createAgentStatus($scannerAgents);
        $selectedAgentId = intval($request->get('agentId'));
        $tag_pk = intval($request->get('tag'));

        $UniqueTagArray = array();
        $this->licenseProjector = new LicenseMap($this->getObject('db.manager'),$groupId,LicenseMap::CONCLUSION,true);
        $vars = $this->createFileListing($tag_pk, $itemTreeBounds, $UniqueTagArray, $selectedAgentId, $groupId, $scanJobProxy);

        return new JsonResponse(array(
            'sEcho' => intval($request->get('sEcho')),
            'aaData' => $vars['fileData'],
            'iTotalRecords' => $vars['iTotalDisplayRecords'],
            'iTotalDisplayRecords' => $vars['iTotalDisplayRecords']
        ) );
    }

    /**
     * @param $tagId
     * @param ItemTreeBounds $itemTreeBounds
     * @param $UniqueTagArray
     * @param $selectedAgentId
     * @param int $groupId
     * @param ScanJobProxy $scanJobProxy
     * @return array
     */
    private function createFileListing($tagId, ItemTreeBounds $itemTreeBounds, &$UniqueTagArray, $selectedAgentId, $groupId, $scanJobProxy)
    {
        if (!empty($selectedAgentId)) {
            $agentName = $this->agentDao->getAgentName($selectedAgentId);
            $selectedScanners = array($agentName=>$selectedAgentId);
        } else {
            $selectedScanners = $scanJobProxy->getLatestSuccessfulAgentIds();
        }

        /** change the license result when selecting one version of nomos */
        $uploadId = $itemTreeBounds->getUploadId();
        $isFlat = isset($_GET['flatten']);

        if ($isFlat) {
            $options = array(UploadTreeProxy::OPT_RANGE => $itemTreeBounds);
        } else {
            $options = array(UploadTreeProxy::OPT_REALPARENT => $itemTreeBounds->getItemId());
        }

        $descendantView = new UploadTreeProxy($uploadId, $options, $itemTreeBounds->getUploadTreeTableName(), 'uberItems');

        $vars['iTotalDisplayRecords'] = $descendantView->count();

        $columnNamesInDatabase = array($isFlat?'ufile_name':'lft');
        $defaultOrder = array(array(0, "asc"));
        $orderString = $this->getObject('utils.data_tables_utility')->getSortingString($_GET, $columnNamesInDatabase, $defaultOrder);

        $offset = GetParm('iDisplayStart', PARM_INTEGER);
        $limit = GetParm('iDisplayLength', PARM_INTEGER);
        if ($offset) {
            $orderString .= " OFFSET $offset";
        }
        if ($limit) {
            $orderString .= " LIMIT $limit";
        }

        /* Get ALL the items under this Uploadtree_pk */
        $sql = $descendantView->getDbViewQuery()." $orderString";
        $dbManager = $this->getObject('db.manager');

        $dbManager->prepare($stmt=__METHOD__.$orderString,$sql);
        $res = $dbManager->execute($stmt,$descendantView->getParams());
        $descendants = $dbManager->fetchAll($res);
        $dbManager->freeResult($res);

        /* Filter out Children that don't have tag */
        if (!empty($tagId)) {
            TagFilter($descendants, $tagId, $itemTreeBounds->getUploadTreeTableName());
        }
        if (empty($descendants)) {
            $vars['fileData'] = array();
            return $vars;
        }

        if ($isFlat) {
            $firstChild = reset($descendants);
            $lastChild = end($descendants);
            $nameRange = array($firstChild['ufile_name'],$lastChild['ufile_name']);
        } else {
            $nameRange = array();
        }

        /*******    File Listing     ************/
        $pfileLicenses = array();
        foreach ($selectedScanners as $agentName=>$agentId) {
            $licensePerPfile = $this->licenseDao->getLicenseIdPerPfileForAgentId($itemTreeBounds, $agentId, $isFlat, $nameRange);
            foreach ($licensePerPfile as $pfile => $licenseRow) {
                foreach ($licenseRow as $licId => $row) {
                    $lic = $this->licenseProjector->getProjectedShortname($licId);
                    $pfileLicenses[$pfile][$lic][$agentName] = $row;
                }
            }
        }

        $baseUri = Traceback_uri().'?mod=sh-agent'.Traceback_parm_keep(array('upload','folder','show'));

        $tableData = array();
        $latestSuccessfulAgentIds = $scanJobProxy->getLatestSuccessfulAgentIds();
        foreach ($descendants as $child) {
            if (empty($child)) {
                continue;
            }
            $tableData[] = $this->createFileDataRow($child, $uploadId, $selectedAgentId,
                 $baseUri, $UniqueTagArray, $isFlat);
        }

        $vars['fileData'] = $tableData;
        return $vars;
    }

    /**
     * @param array $child
     * @param int $uploadId
     * @param int $selectedAgentId
     * @param string $uri
     * @param array $UniqueTagArray
     * @param boolean $isFlat
     * @return array
     */
    private function createFileDataRow($child, $uploadId, $selectedAgentId, $uri, &$UniqueTagArray, $isFlat)
    {
        $fileId = $child['pfile_fk'];
        $childUploadTreeId = $child['uploadtree_pk'];
        $linkUri = '';
        if (!empty($fileId)) {
            $linkUri = Traceback_uri();
            $linkUri .= "?mod=view-license&upload=$uploadId&item=$childUploadTreeId";
            if ($selectedAgentId) {
                $linkUri .= "&agentId=$selectedAgentId";
            }
        }

        /* Determine link for containers */
        $isContainer = Iscontainer($child['ufile_mode']);
        if ($isContainer && !$isFlat) {
            $uploadtree_pk = $child['uploadtree_pk'];
            $linkUri = "$uri&item=" . $uploadtree_pk;
            if ($selectedAgentId) {
                $linkUri .= "&agentId=$selectedAgentId";
            }
        } else if ($isContainer) {
            $uploadtree_pk = Isartifact($child['ufile_mode']) ? DirGetNonArtifact($childUploadTreeId, $this->uploadtree_tablename) : $childUploadTreeId;
            $linkUri = "$uri&item=" . $uploadtree_pk;
            if ($selectedAgentId) {
                $linkUri .= "&agentId=$selectedAgentId";
            }
        }

        /* Populate the output ($VF) - file list */
        /* id of each element is its uploadtree_pk */
        $fileName = htmlspecialchars($child['ufile_name']);
        if ($isContainer) {
            $fileName = "<a href='$linkUri'><span style='color: darkblue'> <b>$fileName</b> </span></a>";
        } else if (!empty($linkUri)) {
            $fileName = "<a href='$linkUri'>$fileName</a>";
        }

        $pfileHash = $this->uploadDao->getUploadHashesFromPfileId($fileId);
        $shRecord = $this->shDao->getSoftwareHetiageRecord($fileId);
        $fileListLinks = FileListLinks($uploadId, $childUploadTreeId, 0, $fileId, true, $UniqueTagArray, $this->uploadtree_tablename, !$isFlat);

        if (! $isContainer) {
            $text = _("Software Heritage");
            $shLink = $this->configuration['api']['url'].$this->configuration['api']['uri'].$pfileHash["sha256"].$this->configuration['api']['content'];
            $fileListLinks .= "[<a href='".$shLink."' target=\"_blank\">$text</a>]";
        }

        return [$fileName, $pfileHash["sha256"], $shRecord["license"], $fileListLinks];
    }
}

register_plugin(new AjaxSHDetailsBrowser());