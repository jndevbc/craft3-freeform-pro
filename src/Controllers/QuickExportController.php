<?php

namespace Solspace\FreeformPro\Controllers;

use craft\db\Query;
use Solspace\Commons\Helpers\PermissionHelper;
use Solspace\Freeform\Elements\Submission;
use Solspace\Freeform\Freeform;
use Solspace\Freeform\Library\Composer\Components\Fields\Interfaces\NoStorageInterface;
use Solspace\Freeform\Library\Composer\Components\Form;
use Solspace\Freeform\Library\Exceptions\Composer\ComposerException;
use Solspace\Freeform\Library\Exceptions\FreeformException;
use Solspace\FreeformPro\Records\ExportSettingRecord;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class QuickExportController extends BaseProController
{
    /**
     * @return Response
     * @throws ComposerException
     */
    public function actionExportDialogue(): Response
    {
        $formId = \Craft::$app->request->getParam('formId');

        $allowedFormIds = $this->getSubmissionsService()->getAllowedSubmissionFormIds();

        /** @var Form[] $forms */
        $forms = [];

        $fields     = [];
        $formModels = $this->getFormsService()->getAllForms();
        foreach ($formModels as $form) {
            if (null !== $allowedFormIds) {
                if (!\in_array($form->id, $allowedFormIds, false)) {
                    continue;
                }
            }

            $forms[$form->id] = $form->getForm();
            foreach ($form->getForm()->getLayout()->getFields() as $field) {
                if ($field instanceof NoStorageInterface || !$field->getId()) {
                    continue;
                }

                $fields[$field->getId()] = $field;
            }
        }

        $firstForm = reset($forms);

        $userId = \Craft::$app->user->getId();

        /** @var ExportSettingRecord $settingRecord */
        $settingRecord = ExportSettingRecord::findOne(
            [
                'userId' => $userId,
            ]
        );

        $setting = [];
        foreach ($forms as $form) {
            $storedFieldIds = $fieldSetting = [];

            if ($settingRecord && isset($settingRecord->setting[$form->getId()])) {
                foreach ($settingRecord->setting[$form->getId()] as $fieldId => $item) {
                    $label     = $item['label'];
                    $isChecked = (bool) $item['checked'];

                    if (is_numeric($fieldId)) {
                        try {
                            $field = $form->getLayout()->getFieldById($fieldId);
                            $label = $field->getLabel();

                            $storedFieldIds[] = $field->getId();
                        } catch (FreeformException $e) {
                            continue;
                        }
                    }

                    $fieldSetting[$fieldId] = [
                        'label'   => $label,
                        'checked' => $isChecked,
                    ];
                }
            }

            if (empty($fieldSetting)) {
                $fieldSetting['id']          = [
                    'label'   => 'ID',
                    'checked' => true,
                ];
                $fieldSetting['title']       = [
                    'label'   => 'Title',
                    'checked' => true,
                ];
                $fieldSetting['ip']          = [
                    'label'   => 'IP',
                    'checked' => true,
                ];
                $fieldSetting['dateCreated'] = [
                    'label'   => 'Date Created',
                    'checked' => true,
                ];
            }

            foreach ($form->getLayout()->getFields() as $field) {
                if (
                    $field instanceof NoStorageInterface ||
                    !$field->getId() ||
                    \in_array($field->getId(), $storedFieldIds, true)
                ) {
                    continue;
                }

                $fieldSetting[$field->getId()] = [
                    'label'   => $field->getLabel(),
                    'checked' => true,
                ];
            }

            $formSetting['form']   = $form;
            $formSetting['fields'] = $fieldSetting;

            $setting[] = $formSetting;
        }

        $selectedFormId = null;
        if ($formId && isset($forms[$formId])) {
            $selectedFormId = $formId;
        } else if ($firstForm) {
            $selectedFormId = $firstForm->getId();
        }

        return $this->renderTemplate(
            'freeform-pro/_components/modals/export_csv',
            [
                'setting'        => $setting,
                'forms'          => $forms,
                'fields'         => $fields,
                'selectedFormId' => $selectedFormId,
            ]
        );
    }

    /**
     * @throws ComposerException
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     */
    public function actionIndex()
    {
        $this->requirePostRequest();
        PermissionHelper::requirePermission(Freeform::PERMISSION_SUBMISSIONS_ACCESS);

        $settings = $this->getExportSettings();

        $formId       = \Craft::$app->request->post('form_id');
        $exportType   = \Craft::$app->request->post('export_type');
        $exportFields = \Craft::$app->request->post('export_fields');

        $formModel = $this->getFormsService()->getFormById($formId);
        if (!$formModel) {
            return;
        }

        $canManageAll = PermissionHelper::checkPermission(Freeform::PERMISSION_SUBMISSIONS_MANAGE);
        if (!$canManageAll) {
            PermissionHelper::requirePermission(
                PermissionHelper::prepareNestedPermission(
                    Freeform::PERMISSION_SUBMISSIONS_MANAGE,
                    $formId
                )
            );
        }

        $form      = $formModel->getForm();
        $fieldData = $exportFields[$form->getId()];

        $settings->setting = $exportFields;
        $settings->save();

        $searchableFields = $labels = [];
        foreach ($fieldData as $fieldId => $data) {
            $label     = $data['label'];
            $isChecked = $data['checked'];

            if (!(bool) $isChecked) {
                continue;
            }

            $labels[$fieldId] = $label;

            $fieldName = is_numeric($fieldId) ? Submission::getFieldColumnName($fieldId) : $fieldId;
            $fieldName = $fieldName === 'title' ? 'c.' . $fieldName : 's.' . $fieldName;

            $searchableFields[] = $fieldName;
        }

        $data = (new Query())
            ->select($searchableFields)
            ->innerJoin('{{%content}} c', 'c.[[elementId]] = s.[[id]]')
            ->from(Submission::TABLE . ' s')
            ->where(['formId' => $form->getId()])
            ->all();

        switch ($exportType) {
            case 'json':
                return $this->getExportProfileService()->exportJson($form, $data);

            case 'xml':
                return $this->getExportProfileService()->exportXml($form, $data);

            case 'text':
                return $this->getExportProfileService()->exportText($form, $data);

            case 'csv':
            default:
                return $this->getExportProfileService()->exportCsv($form, $labels, $data);
        }
    }

    /**
     * @return ExportSettingRecord
     */
    private function getExportSettings(): ExportSettingRecord
    {
        $userId   = \Craft::$app->user->getId();
        $settings = ExportSettingRecord::findOne(
            [
                'userId' => $userId,
            ]
        );

        if (!$settings) {
            $settings = ExportSettingRecord::create($userId);
        }

        return $settings;
    }
}
