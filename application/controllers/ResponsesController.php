<?php

/**
 * class ResponsesController
 **/
class ResponsesController extends LSBaseController
{
    /**
     * responses constructor.
     * @param $controller
     * @param $id
     */
    public function __construct($controller, $id)
    {
        parent::__construct($controller, $id);

        App()->loadHelper('surveytranslator');
    }

    /**
     * Set filters for all actions
     * @return string[]
     */
    public function filters()
    {
        return [
            'postOnly + delete, deleteSingle, deleteAttachments'
        ];
    }

    /**
     * Override default getActionParams
     * @return array
     */
    public function getActionParams()
    {
        return array_merge($_GET, $_POST);
    }

    /**
     * this is part of renderWrappedTemplate implement in old responses.php
     *
     * @param string $view
     * @return bool
     */
    public function beforeRender($view)
    {
        App()->getClientScript()->registerCssFile(App()->getConfig('publicstyleurl') . 'browse.css');

        $surveyId = (int)App()->request->getParam('surveyId');
        $oSurvey = Survey::model()->findByPk($surveyId);
        $this->aData['display']['menu_bars'] = false;
        $this->aData['subaction'] = gT("Responses and statistics");
        $this->aData['display']['menu_bars']['browse'] = gT('Browse responses'); // browse is independent of the above
        $this->aData['title_bar']['title'] = gT('Browse responses') . ': ' . $oSurvey->currentLanguageSettings->surveyls_title;
        $this->aData['topBar']['type'] = 'responses';
        $this->layout = 'layout_questioneditor';

        return parent::beforeRender($view);
    }

    /**
     * @param int $surveyId
     * @param string $token
     */
    public function actionViewbytoken(int $surveyId, string $token): void
    {
        // Get Response ID from token
        $oResponse = SurveyDynamic::model($surveyId)->findByAttributes(['token' => $token]);
        if (!$oResponse) {
            App()->user->setFlash('error', gT("Sorry, this response was not found."));
            $this->redirect(["responses/browse/surveyId/{$surveyId}"]);
        } else {
            $this->redirect(["responses/view/", 'surveyId' => $surveyId, 'id' => $oResponse->id]);
        }
    }

    /**
     * View a single response as queXML PDF
     *
     * @param int $surveyId
     * @param int $id
     * @param string $browseLang
     * @throws CException
     * @throws CHttpException
     */
    public function actionViewquexmlpdf(int $surveyId, int $id, string $browseLang = ''): void
    {
        if (Permission::model()->hasSurveyPermission($surveyId, 'responses', 'read')) {
            $aData = $this->getData($surveyId, $id, $browseLang);
            $sBrowseLanguage = $aData['language'];
            Yii::import("application.libraries.admin.quexmlpdf", true);
            $quexmlpdf = new quexmlpdf();
            // Setting the selected language for printout
            App()->setLanguage($sBrowseLanguage);
            $quexmlpdf->setLanguage($sBrowseLanguage);
            set_time_limit(120);
            App()->loadHelper('export');
            $quexml = quexml_export($surveyId, $sBrowseLanguage, $id);
            $quexmlpdf->create($quexmlpdf->createqueXML($quexml));
            $quexmlpdf->Output("$surveyId-$id-queXML.pdf", 'D');
        } else {
            App()->user->setFlash('error', gT("You do not have permission to access this page."));
            $this->redirect(['surveyAdministration/view', 'surveyid' => $surveyId]);
        }
    }

    /**
     * View a single response in detail
     *
     * @param int $surveyId
     * @param int $id
     * @param string $browseLang
     * @throws CException
     * @throws CHttpException
     */
    public function actionView(int $surveyId, int $id, string $browseLang = ''): void
    {

        // logging for webserver when parameter is somehting like $surveyid=125<script ...
        if (!is_numeric(Yii::app()->request->getParam('surveyId'))) {
            throw new CHttpException(403, gT("Invalid survey ID"));
        }
        if (!is_numeric(Yii::app()->request->getParam('id'))) {
            throw new CHttpException(403, gT("Invalid response ID"));
        }
        $survey = Survey::model()->findByPk($surveyId);

        if (Permission::model()->hasSurveyPermission($surveyId, 'responses', 'read')) {
            $aData = $this->getData($surveyId, $id, $browseLang);
            $sBrowseLanguage = $aData['language'];

            extract($aData, EXTR_OVERWRITE);


            $fieldmap = createFieldMap($survey, 'full', false, false, $aData['language']);
            $bHaveToken = $survey->anonymized == "N" && tableExists('tokens_' . $surveyId); // Boolean : show (or not) the token
            if (!Permission::model()->hasSurveyPermission($surveyId, 'tokens', 'read')) {
                // If not allowed to read: remove it
                unset($fieldmap['token']);
                $bHaveToken = false;
            }
            //add token to top of list if survey is not private
            if ($bHaveToken) {
                $fnames[] = ["token", gT("Access code"), 'code' => 'token'];
                $fnames[] = ["firstname", gT("First name"), 'code' => 'firstname']; // or token:firstname ?
                $fnames[] = ["lastname", gT("Last name"), 'code' => 'lastname'];
                $fnames[] = ["email", gT("Email"), 'code' => 'email'];
            }
            $fnames[] = ["submitdate", gT("Submission date"), gT("Completed"), "0", 'D', 'code' => 'submitdate'];
            $fnames[] = ["completed", gT("Completed"), "0"];

            foreach ($fieldmap as $field) {
                if ($field['fieldname'] == 'lastpage' || $field['fieldname'] == 'submitdate') {
                    continue;
                }
                if ($field['type'] == 'interview_time') {
                    continue;
                }
                if ($field['type'] == 'page_time') {
                    continue;
                }
                if ($field['type'] == 'answer_time') {
                    continue;
                }

                //$question = $field['question'];
                $question = viewHelper::getFieldText($field);

                if ($field['type'] != Question::QT_VERTICAL_FILE_UPLOAD) {
                    $fnames[] = [
                        $field['fieldname'],
                        viewHelper::getFieldText($field),
                        'code' => viewHelper::getFieldCode($field, ['LEMcompat' => true])
                    ];
                } elseif ($field['aid'] !== 'filecount') {
                    $qidattributes = QuestionAttribute::model()->getQuestionAttributes($field['qid']);

                    for ($i = 0; $i < $qidattributes['max_num_of_files']; $i++) {
                        $filenum = sprintf(gT("File %s"), $i + 1);
                        if ($qidattributes['show_title'] == 1) {
                            $fnames[] = [
                                $field['fieldname'],
                                "{$filenum} - {$question} (" . gT('Title') . ")",
                                'code'     => viewHelper::getFieldCode($field) . '(title)',
                                "type"     => Question::QT_VERTICAL_FILE_UPLOAD,
                                "metadata" => "title",
                                "index"    => $i
                            ];
                        }

                        if ($qidattributes['show_comment'] == 1) {
                            $fnames[] = [
                                $field['fieldname'],
                                "{$filenum} - {$question} (" . gT('Comment') . ")",
                                'code'     => viewHelper::getFieldCode($field) . '(comment)',
                                "type"     => Question::QT_VERTICAL_FILE_UPLOAD,
                                "metadata" => "comment",
                                "index"    => $i
                            ];
                        }

                        $fnames[] = [
                            $field['fieldname'],
                            "{$filenum} - {$question} (" . gT('File name') . ")",
                            'code'     => viewHelper::getFieldCode($field) . '(name)',
                            "type"     => "|",
                            "metadata" => "name",
                            "index"    => $i,
                            'qid'      => $field['qid']
                        ];
                        $fnames[] = [
                            $field['fieldname'],
                            "{$filenum} - {$question} (" . gT('File size') . ")",
                            'code'     => viewHelper::getFieldCode($field) . '(size)',
                            "type"     => "|",
                            "metadata" => "size",
                            "index"    => $i
                        ];
                    }
                } else {
                    $fnames[] = [$field['fieldname'], gT("File count")];
                }
            }

            $nfncount = count($fnames) - 1;
            if ($id < 1) {
                $id = 1;
            }

            $exist = SurveyDynamic::model($surveyId)->exist($id);
            $next = SurveyDynamic::model($surveyId)->next($id, true);
            $previous = SurveyDynamic::model($surveyId)->previous($id, true);
            $aData['exist'] = $exist;
            $aData['next'] = $next;
            $aData['previous'] = $previous;
            $aData['id'] = $id;

            if ($exist) {
                $oPurifier = new CHtmlPurifier();
                //SHOW INDIVIDUAL RECORD
                $oCriteria = new CDbCriteria();
                if ($bHaveToken) {
                    $oCriteria = SurveyDynamic::model($surveyId)->addTokenCriteria($oCriteria);
                }

                $oCriteria->addCondition("id = {$id}");
                $iIdresult = SurveyDynamic::model($surveyId)->find($oCriteria);
                if ($bHaveToken) {
                    $aResult = array_merge(
                        $iIdresult->tokens->decrypt()->attributes,
                        $iIdresult->decrypt()->attributes
                    );
                } else {
                    $aResult = $iIdresult->decrypt()->attributes;
                }
                $id = $aResult['id'];
                $rlanguage = $aResult['startlanguage'];
                $aData['bHasFile'] = false;
                if (isset($rlanguage)) {
                    $aData['rlanguage'] = $rlanguage;
                }
                $highlight = false;
                $aData['answers'] = [];
                for ($i = 0; $i < $nfncount + 1; $i++) {
                    if ($fnames[$i][0] != 'completed' && is_null($aResult[$fnames[$i][0]])) {
                        continue; // irrelevant, so don't show
                    }
                    $inserthighlight = '';
                    if ($highlight) {
                        $inserthighlight = "class='highlight'";
                    }

                    if ($fnames[$i][0] == 'completed') {
                        if ($aResult['submitdate'] == null || $aResult['submitdate'] == "N") {
                            $answervalue = "N";
                        } else {
                            $answervalue = "Y";
                        }
                    } elseif (isset($fnames[$i]['type']) && $fnames[$i]['type'] == Question::QT_VERTICAL_FILE_UPLOAD) {
                        // File upload question type.
                        $index = $fnames[$i]['index'];
                        $metadata = $fnames[$i]['metadata'];
                        $phparray = json_decode_ls($aResult[$fnames[$i][0]]);

                        if (isset($phparray[$index])) {
                            switch ($metadata) {
                                case "size":
                                    $answervalue = sprintf(gT("%s KB"), intval($phparray[$index][$metadata]));
                                    break;
                                case "name":
                                    $answervalue = CHtml::link(
                                        htmlspecialchars(
                                            $oPurifier->purify(rawurldecode($phparray[$index][$metadata]))
                                        ),
                                        $this->createUrl(
                                            "responses/downloadfile",
                                            [
                                                "surveyId"    => $surveyId,
                                                "responseId" => $id,
                                                "qid"        => $fnames[$i]['qid'],
                                                "index"      => $index
                                            ]
                                        )
                                    );
                                    break;
                                default:
                                    $answervalue = htmlspecialchars(
                                        strip_tags(
                                            stripJavaScript($phparray[$index][$metadata])
                                        )
                                    );
                            }
                            $aData['bHasFile'] = true;
                        } else {
                            $answervalue = "";
                        }
                    } else {
                        $answervalue = htmlspecialchars(
                            strip_tags(
                                stripJavaScript(
                                    getExtendedAnswer(
                                        $surveyId,
                                        $fnames[$i][0],
                                        $aResult[$fnames[$i][0]],
                                        $sBrowseLanguage
                                    )
                                )
                            ),
                            ENT_QUOTES
                        );
                    }
                    $aData['inserthighlight'] = $inserthighlight;
                    $aData['fnames'] = $fnames;
                    $aData['answers'][] = [
                        'answervalue' => $answervalue,
                        'i' => $i
                    ];
                }
            } else {
                App()->session['flashmessage'] = gT("This response ID is invalid.");
            }

            $aData['sidemenu']['state'] = false;
            // This resets the url on the close button to go to the upper view
            $aData['closeUrl'] = $this->createUrl("responses/browse/", ['surveyId' => $surveyId]);
            $aData['topBar']['name'] = 'baseTopbar_view';
            $aData['topBar']['rightSideView'] = 'responseViewTopbarRight_view';

            $this->aData = $aData;
            $this->render('browseidrow_view', [
                'id'              => $aData['id'],
                'surveyid'        => $aData['surveyId'],
                'answers'         => $aData['answers'],
                'inserthighlight' => $aData['inserthighlight'],
                'fnames'          => $aData['fnames'],
            ]);
        } else {
            App()->user->setFlash('error', gT("You do not have permission to access this page."));
            $this->redirect(['surveyAdministration/view', 'surveyid' => $surveyId]);
        }
    }

    /**
     * Shows the responses summary
     *
     * @param int $surveyId
     */
    public function actionIndex(int $surveyId): void
    {
        // logging for webserver when parameter is somehting like $surveyid=125<script ...
        if (!is_numeric(Yii::app()->request->getParam('surveyId'))) {
            throw new CHttpException(403, gT("Invalid survey ID"));
        }
        $survey = Survey::model()->findByPk($surveyId);
        $aData = $this->getData($surveyId);

        $aData['num_total_answers'] = SurveyDynamic::model($surveyId)->count();
        $aData['num_completed_answers'] = SurveyDynamic::model($surveyId)->count('submitdate IS NOT NULL');
        if ($survey->hasTokensTable && Permission::model()->hasSurveyPermission($surveyId, 'tokens', 'read')) {
            $aData['with_token'] = App()->db->schema->getTable($survey->tokensTableName);
            $aData['tokeninfo'] = Token::model($surveyId)->summary();
        }

        $aData['topBar']['name'] = 'baseTopbar_view';
        $aData['topBar']['leftSideView'] = 'responsesTopbarLeft_view';

        $this->aData = $aData;
        $this->render('browseindex_view', [
          'num_completed_answers' => $aData['num_completed_answers'],
          'num_total_answers'     => $aData['num_total_answers'],
          'tokeninfo'             => $aData['tokeninfo'],
          'with_token'            => $aData['with_token']
        ]);
    }

    /**
     * Show responses for survey
     *
     * @param int $surveyId
     * @return void
     */
    public function actionBrowse(int $surveyId): void
    {
        // logging for webserver when parameter is somehting like $surveyid=125<script ...
        if (!is_numeric(Yii::app()->request->getParam('surveyId'))) {
            throw new CHttpException(403, gT("Invalid survey ID"));
        }
        $survey = Survey::model()->findByPk($surveyId);
        $displaymode = App()->request->getPost('displaymode', null);

        if ($displaymode !== null) {
            $this->setGridDisplay($displaymode);
        }

        if (Permission::model()->hasSurveyPermission($surveyId, 'responses', 'read')) {
            App()->getClientScript()->registerScriptFile(
                App()->getConfig('adminscripts') .
                'listresponse.js',
                LSYii_ClientScript::POS_BEGIN
            );
            App()->getClientScript()->registerScriptFile(
                App()->getConfig('adminscripts') .
                'tokens.js',
                LSYii_ClientScript::POS_BEGIN
            );

            // Basic data for the view
            $aData = $this->getData($surveyId);
            $aData['surveyid'] = $surveyId;
            $aData['sidemenu']['state'] = false;
            $aData['issuperadmin'] = Permission::model()->hasGlobalPermission('superadmin');
            $aData['hasUpload'] = hasFileUploadQuestion($surveyId);
            $aData['fieldmap'] = createFieldMap($survey, 'full', true, false, $aData['language']);
            $aData['dateformatdetails'] = getDateFormatData(App()->session['dateformat']);

            ////////////////////
            // Setting the grid

            // Basic variables
            $bHaveToken = $survey->anonymized == "N" && tableExists('tokens_' . $surveyId) && Permission::model()->hasSurveyPermission($surveyId, 'tokens', 'read'); // Boolean : show (or not) the token
            $model = SurveyDynamic::model($surveyId);
            $model->bEncryption = true;

            // Reset filters from stats
            if (App()->request->getParam('filters') == "reset") {
                App()->user->setState('sql_' . $surveyId, '');
            }

            // Page size
            if (App()->request->getParam('pageSize')) {
                App()->user->setState('pageSize', (int)App()->request->getParam('pageSize'));
            }

            // Model filters
            if (isset($_SESSION['survey_' . $surveyId])) {
                $sessionSurveyArray = App()->session->get('survey_' . $surveyId);
                $visibleColumns = $sessionSurveyArray['filteredColumns'] ?? null;
                if (!empty($visibleColumns)) {
                    $model->setAttributes($visibleColumns, false);
                }
            }
            // Using safe search on dynamic column names would be far too much complex.
            // So we pass over the safe validation and directly set attributes (second parameter of setAttributes to false).
            // see: http://www.yiiframework.com/wiki/161/understanding-safe-validation-rules/
            // see: http://www.yiiframework.com/doc/api/1.1/CModel#setAttributes-detail
            if (App()->request->getParam('SurveyDynamic')) {
                $model->setAttributes(App()->request->getParam('SurveyDynamic'), false);
            }

            // Virtual attributes filters
            // Filters on related tables need virtual filters attributes in main model (class variables)
            // Those virtual filters attributes are not set by the setAttributes, they must be set manually
            // @see: http://www.yiiframework.com/wiki/281/searching-and-sorting-by-related-model-in-cgridview/
            $aVirtualFilters = ['completed_filter', 'firstname_filter', 'lastname_filter', 'email_filter'];
            foreach ($aVirtualFilters as $sFilterName) {
                $aParam = App()->request->getParam('SurveyDynamic');
                if (!empty($aParam[$sFilterName])) {
                    $model->$sFilterName = $aParam[$sFilterName];
                }
            }

            // Sets which columns to filter
            $filteredColumns = !empty(isset($_SESSION['survey_' . $surveyId]['filteredColumns'])) ? $_SESSION['survey_' . $surveyId]['filteredColumns'] : null;
            $aData['filteredColumns'] = $filteredColumns;

            // rendering
            $aData['model'] = $model;
            $aData['bHaveToken'] = $bHaveToken;
            $aData['aDefaultColumns'] = $model->defaultColumns; // Some specific columns
            // Page size
            $aData['pageSize'] = App()->user->getState('pageSize', App()->params['defaultPageSize']);

            $aData['topBar']['name'] = 'baseTopbar_view';
            $aData['topBar']['leftSideView'] = 'responsesTopbarLeft_view';

            $this->aData = $aData;
            $this->render('listResponses_view', [
                'surveyid' => $aData['surveyId'],
                'dateformatdetails' => $aData['dateformatdetails'],
                'model' => $aData['model'],
                'bHaveToken' => $aData['bHaveToken'],
                'language' => $aData['language'],
                'pageSize' => $aData['pageSize'],
                'fieldmap' => $aData['fieldmap'],
                'filteredColumns' => $aData['filteredColumns'],

            ]);
        } else {
            App()->user->setFlash('error', gT("You do not have permission to access this page."));
            $this->redirect(['surveyAdministration/view', 'surveyid' => $surveyId]);
        }
    }

    /**
     * Saves the hidden columns for response browsing in the session
     * @access public
     * @param int $surveyId
     */
    public function actionSetFilteredColumns(int $surveyId): void
    {
        // logging for webserver when parameter is something like $surveyid=125<script ...
        if (!is_numeric(Yii::app()->request->getParam('surveyId'))) {
            throw new CHttpException(403, gT("Invalid survey ID"));
        }
        if (Permission::model()->hasSurveyPermission($surveyId, 'responses', 'read')) {
            $aFilteredColumns = [];
            $aColumns = (array)App()->request->getPost('columns');
            if (isset($aColumns)) {
                if (!empty($aColumns)) {
                    foreach ($aColumns as $sColumn) {
                        if (isset($sColumn)) {
                            $aFilteredColumns[] = $sColumn;
                        }
                    }
                    $_SESSION['survey_' . $surveyId]['filteredColumns'] = $aFilteredColumns;
                } else {
                    $_SESSION['survey_' . $surveyId]['filteredColumns'] = [];
                }
            }
        }
        $this->redirect(["responses/browse", "surveyId" => $surveyId]);
    }

    /**
     * Deletes multiple responses (massive action)
     *
     * @access public
     * @param int $surveyId
     * @return void
     * @throws CDbException
     * @throws CException
     * @throws CHttpException
     */
    public function actionDelete(int $surveyId): void
    {
        if (!is_numeric(Yii::app()->request->getParam('surveyId'))) {
            throw new CHttpException(403, gT("Invalid survey ID"));
        }
        if (!Permission::model()->hasSurveyPermission($surveyId, 'responses', 'delete')) {
            throw new CHttpException(403, gT("You do not have permission to access this page."));
        }
        if (!App()->getRequest()->isPostRequest) {
            throw new CHttpException(405, gT("Invalid action"));
        }
        Yii::import('application.helpers.admin.ajax_helper', true);

        $ResponseId = (App()->request->getPost('sItems') != '') ? json_decode(App()->request->getPost('sItems')) : json_decode(App()->request->getParam('sResponseId'), true);
        if (App()->request->getPost('modalTextArea') != '') {
            $ResponseId = explode(',', App()->request->getPost('modalTextArea'));
            foreach ($ResponseId as $key => $sResponseId) {
                $ResponseId[$key] = str_replace(' ', '', $sResponseId);
            }
        }

        $aResponseId = (is_array($ResponseId)) ? $ResponseId : [$ResponseId];
        $errors = 0;
        $timingErrors = 0;

        foreach ($aResponseId as $iResponseId) {
            $resultErrors = $this->deleteResponse($surveyId, $iResponseId);
            $errors += $resultErrors['numberOfErrors'];
            $timingErrors += $resultErrors['numberOfTimingErrors'];
        }

        if ($errors || $timingErrors) {
            $message = ($errors) ? ngT("A response was not deleted.|{n} responses were not deleted.", $errors) : "";
            $message .= ($timingErrors) ? ngT("A timing record was not deleted.|{n} timing records were not deleted.", $errors) : "";
            if (App()->getRequest()->isAjaxRequest) {
                ls\ajax\AjaxHelper::outputError($message);
            } else {
                App()->user->setFlash('error', $message);
                $this->redirect(["responses/browse", "surveyId" => $surveyId]);
            }
        }
        if (App()->getRequest()->isAjaxRequest) {
            ls\ajax\AjaxHelper::outputSuccess(gT('Response(s) deleted.'));
        }
        App()->user->setFlash('success', gT('Response(s) deleted.'));
        $this->redirect(["responses/browse", "surveyId" => $surveyId]);
    }

    /**
     * Deletes a single response and redirects to the gridview.
     *
     * @param int $surveyId -- the survey id
     * @param int $responseId -- the response id to be deleted
     * @throws CDbException
     * @throws CHttpException
     */
    public function actionDeleteSingle(int $surveyId, int $responseId): void
    {
        if (!is_numeric(Yii::app()->request->getParam('surveyId'))) {
            throw new CHttpException(403, gT("Invalid survey ID"));
        }
        if (!is_numeric(Yii::app()->request->getParam('responseId'))) {
            throw new CHttpException(403, gT("Invalid response ID"));
        }
        if (!Permission::model()->hasSurveyPermission($surveyId, 'responses', 'delete')) {
            throw new CHttpException(403, gT("You do not have permission to access this page."));
        }

        $resultErrors = $this->deleteResponse($surveyId, $responseId);
        if ($resultErrors['numberOfErrors'] > 0 || $resultErrors['numberOfTimingErrors']) {
            $message = gt('Response could not be deleted');
            App()->user->setFlash('error', $message);
            $this->redirect(["responses/browse", "surveyId" => $surveyId]);
        }

        App()->user->setFlash('success', gT('Response deleted.'));
        $this->redirect(["responses/browse", "surveyId" => $surveyId]);
    }

    /**
     * Download individual file by response and filename
     *
     * @access public
     * @param int $surveyId : survey id
     * @param int $responseId
     * @param int $qid
     * @param int $index
     * @return void
     * @throws CHttpException
     */
    public function actionDownloadfile(int $surveyId, int $responseId, int $qid, int $index): void
    {
        if (!is_numeric(Yii::app()->request->getParam('surveyId'))) {
            throw new CHttpException(403, gT("Invalid survey ID"));
        }
        if (!is_numeric(Yii::app()->request->getParam('responseId'))) {
            throw new CHttpException(403, gT("Invalid response ID"));
        }
        if (!is_numeric(Yii::app()->request->getParam('qid'))) {
            throw new CHttpException(403, gT("Invalid question ID"));
        }
        $oSurvey = Survey::model()->findByPk($surveyId);
        if (!$oSurvey->isActive) {
            App()->user->setFlash('error', gT('Sorry, this file was not found.'));
            $this->redirect(["surveyAdministration/view", "surveyid" => $surveyId]);
        }

        if (Permission::model()->hasSurveyPermission($surveyId, 'responses', 'read')) {
            $oResponse = Response::model($surveyId)->findByPk($responseId);
            if (is_null($oResponse)) {
                App()->user->setFlash('error', gT('Found no response with ID %d'), $responseId);
                $this->redirect(["responses/browse", "surveyId" => $surveyId]);
            }
            $aQuestionFiles = $oResponse->getFiles($qid);
            if (isset($aQuestionFiles[$index])) {
                $aFile = $aQuestionFiles[$index];
                // Real path check from here: https://stackoverflow.com/questions/4205141/preventing-directory-traversal-in-php-but-allowing-paths
                $sDir = Yii::app()->getConfig('uploaddir') . DIRECTORY_SEPARATOR . "surveys" . DIRECTORY_SEPARATOR . $surveyId . DIRECTORY_SEPARATOR . "files" . DIRECTORY_SEPARATOR;
                $sFileRealName = $sDir . $aFile['filename'];
                $sRealUserPath = realpath($sFileRealName);
                if ($sRealUserPath === false) {
                    throw new CHttpException(404, "File not found.");
                } elseif (strpos($sRealUserPath, $sDir) !== 0) {
                        throw new CHttpException(403, "File cannot be accessed.");
                } else {
                    $mimeType = CFileHelper::getMimeType($sFileRealName, null, false);
                    if (is_null($mimeType)) {
                        $mimeType = "application/octet-stream";
                    }
                    @ob_clean();
                    header('Content-Description: File Transfer');
                    header('Content-Type: ' . $mimeType);
                    header('Content-Disposition: attachment; filename="' . sanitize_filename(rawurldecode($aFile['name'])) . '"');
                    header('Content-Transfer-Encoding: binary');
                    header('Expires: 0');
                    header("Cache-Control: must-revalidate, no-store, no-cache");
                    header('Content-Length: ' . filesize($sFileRealName));
                    readfile($sFileRealName);
                    exit;
                }
            }
            App()->user->setFlash('error', gT('Sorry, this file was not found.'));
            $this->redirect(["responses/browse", "surveyId" => $surveyId]);
        } else {
            throw new CHttpException(403, gT("You do not have permission to access this page."));
        }
    }

    /**
     * Construct a zip files from a list of response
     *
     * @access public
     * @param int $surveyId : survey id
     * @param string $responseIds : list of responses as string
     * @return void application/zip
     * @throws CException
     */
    public function actionDownloadfiles(int $surveyId, string $responseIds = ''): void
    {
        if (!is_numeric(Yii::app()->request->getParam('surveyId'))) {
            throw new CHttpException(403, gT("Invalid survey ID"));
        }
        if (Permission::model()->hasSurveyPermission($surveyId, 'responses', 'read')) {
            $oSurvey = Survey::model()->findByPk($surveyId);
            if (!$oSurvey->isActive) {
                App()->user->setFlash('error', gT('Sorry, this file was not found.'));
                $this->redirect(["surveyAdministration/view", "surveyid" => $surveyId]);
            }
            if (!$responseIds) {
                // No response id : get all survey files
                $oCriteria = new CDbCriteria();
                $oCriteria->select = "id";
                $oSurvey = SurveyDynamic::model($surveyId);
                $aResponseId = $oSurvey->getCommandBuilder()
                    ->createFindCommand($oSurvey->tableSchema, $oCriteria)
                    ->queryColumn();
            } else {
                $aResponseId = explode(",", $responseIds);
            }
            if (!empty($aResponseId)) {
                // Now, zip all the files in the filelist
                if (count($aResponseId) === 1) {
                    $zipfilename = "Files_for_survey_{$surveyId}_response_{$aResponseId[0]}.zip";
                } else {
                    $zipfilename = "Files_for_survey_{$surveyId}.zip";
                }
                $this->zipFiles($surveyId, $aResponseId, $zipfilename);
            } else {
                // No response : redirect to browse with a alert
                App()->user->setFlash('error', gT('The requested files do not exist on the server.'));
                $this->redirect(["responses/browse", "surveyId" => $surveyId]);
            }
        } else {
            throw new CHttpException(403, gT("You do not have permission to access this page."));
        }
    }

    /**
     * Delete all uploaded files for one response.
     *
     * @param int $surveyId
     * @param int|null $responseId
     * @return void
     * @throws CException
     * @throws CHttpException
     */
    public function actionDeleteAttachments(int $surveyId, int $responseId = null): void
    {
        if (!is_numeric(Yii::app()->request->getParam('surveyId'))) {
            throw new CHttpException(403, gT("Invalid survey ID"));
        }
        if (!Permission::model()->hasSurveyPermission($surveyId, 'responses', 'update')) {
            throw new CHttpException(403, gT("You do not have permission to access this page."));
        }
        $request = App()->request;
        if (!$request->isPostRequest) {
            throw new CHttpException(405, gT("Invalid action"));
        }

        $stringItems = json_decode($request->getPost('sItems'));
        // Cast all ids to int.
        $items = array_map(
            function ($id) {
                return (int)$id;
            },
            is_array($stringItems) ? $stringItems : []
        );
        $responseIds = $responseId !== null ? [$responseId] : $items;

        Yii::import('application.helpers.admin.ajax_helper', true);
        $allErrors = [];
        $allSuccess = 0;

        foreach ($responseIds as $responseIdLoop) {
            $response = Response::model($surveyId)->findByPk($responseIdLoop);
            if ($response !== null) {
                [$success, $errors] = $response->deleteFilesAndFilename();
                if (empty($errors)) {
                    $allSuccess += $success;
                } else {
                    // Could not delete all files.
                    $allErrors = array_merge($allErrors, $errors);
                }
            } else {
                $allErrors[] = sprintf(gT('Found no response with ID %d'), $responseIdLoop);
            }
        }
        if (!empty($allErrors)) {
            $message = gT('Error: Could not delete some files: ') . implode(', ', $allErrors);
            if ($request->isAjaxRequest) {
                ls\ajax\AjaxHelper::outputError(
                    $message
                );
                App()->end();
            }
            App()->user->setFlash('error', $message);
            $this->redirect(["responses/browse", "surveyId" => $surveyId]);
        }
        $message = sprintf(ngT('%d file deleted.|%d files deleted.', $allSuccess), $allSuccess);
        if ($request->isAjaxRequest) {
            ls\ajax\AjaxHelper::outputSuccess($message);
            App()->end();
        }
        App()->user->setFlash('success', $message);
        $this->redirect(["responses/browse", "surveyId" => $surveyId]);
    }

    /**
     * Time statistics for responses
     *
     * @param int $surveyId
     * @return void
     */
    public function actionTime(int $surveyId): void
    {
        $aData = $this->getData($surveyId);

        $aData['columns'] = [
            [
                'header'            => gT('ID'),
                'name'              => 'id',
                'value'             => '$data->id',
                'headerHtmlOptions' => ['class' => 'hidden-xs'],
                'htmlOptions'       => ['class' => 'hidden-xs']
            ],
            [
                'header' => gT('Total time'),
                'name'   => 'interviewtime',
                'value'  => '$data->interviewtime'
            ]
        ];

        $fields = createTimingsFieldMap($surveyId, 'full', true, false, $aData['language']);
        foreach ($fields as $fielddetails) {
            // headers for answer id and time data
            if ($fielddetails['type'] === 'id') {
                $fnames[] = [$fielddetails['fieldname'], $fielddetails['question']];
            }

            if ($fielddetails['type'] === 'interview_time') {
                $fnames[] = [$fielddetails['fieldname'], gT('Total time')];
            }

            if ($fielddetails['type'] === 'page_time') {
                $fnames[] = [$fielddetails['fieldname'], gT('Group') . ": " . $fielddetails['group_name']];
                $aData['columns'][] = [
                    'header' => gT('Group: ') . $fielddetails['group_name'],
                    'name'   => $fielddetails['fieldname']
                ];
            }

            if ($fielddetails['type'] === 'answer_time') {
                $fnames[] = [$fielddetails['fieldname'], gT('Question') . ": " . $fielddetails['title']];
                $aData['columns'][] = [
                    'header' => gT('Question: ') . $fielddetails['title'],
                    'name'   => $fielddetails['fieldname']
                ];
            }
        }
        // Set number of page
        if (App()->request->getParam('pageSize')) {
            App()->user->setState('pageSize', (int)App()->request->getParam('pageSize'));
        }

        //interview Time statistics
        $aData['model'] = SurveyTimingDynamic::model($surveyId);

        $aData['pageSize'] = App()->user->getState('pageSize', Yii::app()->params['defaultPageSize']);
        $aData['statistics'] = SurveyTimingDynamic::model($surveyId)->statistics();
        $aData['num_total_answers'] = SurveyDynamic::model($surveyId)->count();
        $aData['num_completed_answers'] = SurveyDynamic::model($surveyId)->count('submitdate IS NOT NULL');

        $aData['topBar']['name'] = 'baseTopbar_view';
        $aData['topBar']['leftSideView'] = 'responsesTopbarLeft_view';

        $this->aData = $aData;
        $this->render('browsetimerow_view', [
            'model'      => $aData['model'],
            'surveyId'  => $aData['surveyId'],
            'language'   => $aData['language'],
            'pageSize'   => $aData['pageSize'],
            'columns'    => $aData['columns'],
            'statistics' => $aData['statistics'],
        ]);
    }

    /**
     * Responsible for setting the session variables for attribute map page redirect
     * @param bool $unset
     * @param int|null $surveyId
     */
    public function actionSetSession(bool $unset = false, int $surveyId = null): void
    {
        unset(App()->session['responsesid']);
        if (!$unset) {
            App()->session['responsesid'] = App()->request->getPost('itemsid');
        } else {
            $this->redirect(["admin/export", "sa" => "exportresults", "surveyid" => $surveyId]);
        }
    }

    /**
     * Change the value of the max characters to elipsize headers/questions in reponse grid.
     * It's called via ajax request
     *
     * @param string $displaymode
     * @return void
     */
    public function setGridDisplay($displaymode): void
    {
        if ($displaymode === 'extended') {
            App()->user->setState('responsesGridSwitchDisplayState', 'extended');
            App()->user->setState('defaultEllipsizeHeaderValue', 1000);
            App()->user->setState('defaultEllipsizeQuestionValue', 1000);
        } else {
            App()->user->setState('responsesGridSwitchDisplayState', 'compact');
            App()->user->setState('defaultEllipsizeHeaderValue', App()->params['defaultEllipsizeHeaderValue']);
            App()->user->setState('defaultEllipsizeQuestionValue', App()->params['defaultEllipsizeQuestionValue']);
        }
    }

    /**
     * Supply an array with the responseIds and all files will be added to the zip
     * and it will be be spit out on success
     *
     * @param int $surveyId
     * @param array $reponseId
     * @param string $zipfilename
     */
    private function zipFiles(int $surveyId, array $reponseId, string $zipfilename): void
    {
        $tmpdir = App()->getConfig('uploaddir') . DIRECTORY_SEPARATOR . "surveys" . DIRECTORY_SEPARATOR . $surveyId . DIRECTORY_SEPARATOR . "files" . DIRECTORY_SEPARATOR;

        $filelist = [];
        $responses = Response::model($surveyId)->findAllByPk($reponseId);
        $filecount = 0;
        foreach ($responses as $response) {
            foreach ($response->getFiles() as $fileInfo) {
                $filecount++;
                /*
                * Now add the file to the archive, prefix files with responseid_index to keep them
                * unique. This way we can have 234_1_image1.gif, 234_2_image1.gif as it could be
                * files from a different source with the same name.
                */
                if (file_exists($tmpdir . basename($fileInfo['filename']))) {
                    $filelist[] = [
                        $tmpdir . basename($fileInfo['filename']),
                        sprintf("%05s_%02s-%s_%02s-%s", $response->id, $filecount, $fileInfo['question']['title'], $fileInfo['index'], sanitize_filename(rawurldecode($fileInfo['name'])))
                    ];
                }
            }
        }

        if (count($filelist) > 0) {
            $zip = new ZipArchive();
            $zip->open($tmpdir . $zipfilename, ZipArchive::CREATE);
            foreach ($filelist as $aFile) {
                $zip->addFile($aFile[0], $aFile[1]);
            }
            $zip->close();
            if (file_exists($tmpdir . '/' . $zipfilename)) {
                @ob_clean();
                header('Content-Description: File Transfer');
                header('Content-Type: application/zip, application/octet-stream');
                header('Content-Disposition: attachment; filename=' . basename($zipfilename));
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header("Cache-Control: must-revalidate, no-store, no-cache");
                header('Content-Length: ' . filesize($tmpdir . "/" . $zipfilename));
                readfile($tmpdir . '/' . $zipfilename);
                unlink($tmpdir . '/' . $zipfilename);
                exit;
            }
        }
        // No files : redirect to browse with a alert
        App()->user->setFlash('error', gT("Sorry, there are no files for this response."));
        $this->redirect(["responses/browse", "surveyId" => $surveyId]);
    }

    /**
     * Used to get responses data for browse etc
     *
     * @param int|null $surveyId
     * @param int|null $responseId
     * @param string|null $language
     * @return array
     */
    private function getData(int $surveyId = null, int $responseId = null, string $language = null): array
    {
        if (!isset($surveyId)) {
            App()->session['flashmessage'] = gT("Invalid survey ID");
            $this->redirect(["admin/index"]);
        }

        $thissurvey = getSurveyInfo($surveyId);

        // Reinit LEMlang and LEMsid: ensure LEMlang are set to default lang, surveyid are set to this survey id
        // Ensure Last GetLastPrettyPrintExpression get info from this sid and default lang
        LimeExpressionManager::SetEMLanguage($thissurvey['oSurvey']->language);
        LimeExpressionManager::SetSurveyId($surveyId);
        LimeExpressionManager::StartProcessingPage(false, true);

        if (!$thissurvey) {
            App()->session['flashmessage'] = gT("Invalid survey ID");
            $this->redirect(["admin/index"]);
        } elseif ($thissurvey['active'] !== 'Y') {
            App()->session['flashmessage'] = gT("This survey has not been activated. There are no results to browse.");
            $this->redirect(["surveyAdministration/view/surveyid/{$surveyId}"]);
        }
        $aData = [];
        // Set the variables in an array
        $aData['surveyId'] = $aData['surveyid'] = $aData['iSurveyId'] = $surveyId;
        if (!empty($responseId)) {
            $aData['iId'] = $responseId;
        }
        $aData['imageurl'] = App()->getConfig('imageurl');
        $aData['action'] = App()->request->getParam('action');
        $aData['all'] = App()->request->getParam('all');

        //OK. IF WE GOT THIS FAR, THEN THE SURVEY EXISTS AND IT IS ACTIVE, SO LETS GET TO WORK.
        if (!empty($language)) {
            $aData['language'] = $language;
            $aData['languagelist'] = $languagelist = Survey::model()->findByPk($surveyId)->additionalLanguages;
            $aData['languagelist'][] = Survey::model()->findByPk($surveyId)->language;
            if (!in_array($aData['language'], $languagelist)) {
                $aData['language'] = $thissurvey['language'];
            }
        } else {
            $aData['language'] = $thissurvey['language'];
        }

        $aData['qulanguage'] = Survey::model()->findByPk($surveyId)->language;

        $aData['surveyoptions'] = '';
        $aData['browseoutput'] = '';

        return $aData;
    }

    /**
     * Deletes a response
     *
     * @param $surveyId
     * @param $iResponseId
     * @return int[]
     * @throws CDbException
     */
    private function deleteResponse($surveyId, $iResponseId): array
    {
        $errors = 0;
        $timingErrors = 0;

        $beforeDataEntryDelete = new PluginEvent('beforeDataEntryDelete');
        $beforeDataEntryDelete->set('iSurveyID', $surveyId);
        $beforeDataEntryDelete->set('iResponseID', $iResponseId);
        App()->getPluginManager()->dispatchEvent($beforeDataEntryDelete);

        $response = Response::model($surveyId)->findByPk($iResponseId);
        if ($response) {
            $result = $response->delete(true);
            if (!$result) {
                ++$errors;
            }
        } else {
            ++$errors;
        }

        return ['numberOfErrors' => $errors, 'numberOfTimingErrors' => $timingErrors];
    }
}
