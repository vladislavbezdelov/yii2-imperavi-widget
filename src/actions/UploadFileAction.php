<?php
/**
 * This file is part of yii2-imperavi-widget.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @see https://github.com/vova07/yii2-imperavi-widget
 */

namespace vova07\imperavi\actions;

use App;
use yii\base\Action;
use domain\v1\document\classes\StaticServerFileSync;
use domain\v1\profile\repositories\ar\UserProfileRepository;
use vova07\imperavi\Widget;
use Yii;
use yii\base\DynamicModel;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\helpers\Inflector;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * UploadFileAction for images and files.
 *
 * Usage:
 *
 * ```php
 * public function actions()
 * {
 *     return [
 *         'upload-image' => [
 *             'class' => 'vova07\imperavi\actions\UploadFileAction',
 *             'url' => 'http://my-site.com/statics/',
 *             'path' => '/var/www/my-site.com/web/statics',
 *             'unique' => true,
 *             'validatorOptions' => [
 *                 'maxWidth' => 1000,
 *                 'maxHeight' => 1000
 *             ]
 *         ],
 *         'file-upload' => [
 *             'class' => 'vova07\imperavi\actions\UploadFileAction',
 *             'url' => 'http://my-site.com/statics/',
 *             'path' => '/var/www/my-site.com/web/statics',
 *             'uploadOnlyImage' => false,
 *             'translit' => true,
 *             'validatorOptions' => [
 *                 'maxSize' => 40000
 *             ]
 *         ]
 *     ];
 * }
 * ```
 *
 * @author Vasile Crudu <bazillio07@yandex.ru>
 *
 * @link https://github.com/vova07/yii2-imperavi-widget
 */
class UploadFileAction extends Action
{
    /**
     * @var string Path to directory where files will be uploaded.
     */
    public $path;

    /**
     * @var string URL path to directory where files will be uploaded.
     */
    public $url;

    /**
     * @var string Validator name
     */
    public $uploadOnlyImage = true;

    /**
     * @var string Variable's name that Imperavi Redactor sent upon image/file upload.
     */
    public $uploadParam = 'file';

    /**
     * @var bool Whether to replace the file with new one in case they have same name or not.
     */
    public $replace = false;

    /**
     * @var boolean If `true` unique filename will be generated automatically.
     */
    public $unique = true;

    /**
     * In case of `true` this option will be ignored if `$unique` will be also enabled.
     *
     * @var bool Whether to translit the uploaded file name or not.
     */
    public $translit = false;

    /**
     * @var array Model validator options.
     */
    public $validatorOptions = [];

    /**
     * @var string Model validator name.
     */
    private $_validator = 'image';

    /**
     * @inheritdoc
     */
    public function init()
    {
        if ($this->url === null) {
            throw new InvalidConfigException('The "url" attribute must be set.');
        } else {
            $this->url = rtrim($this->url, '/') . '/';
        }
        if ($this->path === null) {
            throw new InvalidConfigException('The "path" attribute must be set.');
        } else {
            $this->path = rtrim(Yii::getAlias($this->path), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

            if (!FileHelper::createDirectory($this->path)) {
                throw new InvalidCallException("Directory specified in 'path' attribute doesn't exist or cannot be created.");
            }
        }
        if ($this->uploadOnlyImage !== true) {
            $this->_validator = 'file';
        }

        Widget::registerTranslations();
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
    	try {
		    if (!Yii::$app->request->isPost) {
			    return $result = ['eror' => 'NoPost'];
		    }
		    if (Yii::$app->request->isPost) {
			    Yii::$app->response->format = Response::FORMAT_JSON;

			    $file = UploadedFile::getInstanceByName($this->uploadParam);
			    $model = new DynamicModel(['file' => $file]);
			    $model->addRule('file', $this->_validator, $this->validatorOptions)->validate();

			    if ($model->hasErrors()) {
				    $result = [
					    'error' => $model->getFirstError('file'),
				    ];
			    } else {
				    if ($this->unique === true && $model->file->extension) {
					    $model->file->name = uniqid() . '.' . $model->file->extension;
				    } elseif ($this->translit === true && $model->file->extension) {
					    $model->file->name = Inflector::slug($model->file->baseName) . '.' . $model->file->extension;
				    }

				    if (file_exists($this->path . $model->file->name) && $this->replace === false) {
					    return [
						    'error' => Yii::t('vova07/imperavi', 'ERROR_FILE_ALREADY_EXIST'),
					    ];
				    }
				    $setImage = $this->setImage($model);
				    if ($setImage['file']) {
					    $result = ['id' => $model->file->name, 'filelink' => $this->url . $model->file->name];
				    } else {
					    $result = ['error' => 'error'];
				    }
				    if ($this->uploadOnlyImage !== true) {
					    $result['filename'] = $model->file->name;
				    }
			    }

			    return $result;
		    } else {
			    throw new BadRequestHttpException('Only POST is allowed');
		    }
	    } catch (\Exception $exception) {
    		return ['error' => $exception->getMessage()];
	    }
    }

	/**
	 * @param $model
	 * @return mixed
	 */
	public function setImage($model)
	{
		if ($model) {
			$uploadedAttributes = $model->getAttributes(['file']);
			foreach ($uploadedAttributes as $key => $value) {
				if ($value instanceof UploadedFile) {
					$modifiedAttributes[$key] = $uploadedAttributes[$key]; //$modifiedAttributes store only model attributes which value is instance of UploadedFile
				}
			}
			$this->modifyFileName($model, $modifiedAttributes);
			$results = $this->sendUploadFiles($model, $modifiedAttributes, 'service/');
			return $results;
		}
	}

	/**
	 * @param $model
	 * @param array $attributeNames
	 */
	public function modifyFileName(&$model, array $attributeNames)
	{
		foreach ($attributeNames as $key => $value) {
			$name = substr($model->$key->name,0, strrpos($model->$key->name, '.',-1));
			$model->$key->name = $name.time().'.'.$model->$key->size.'x'.UserProfileRepository::$maxSize.'.png';
		}
	}

	/**
	 * @param $model
	 * @param array $attributeNames
	 * @param string $path
	 * @return array
	 */
	public function sendUploadFiles($model, array $attributeNames, string $path) : array
	{
		$downloadResults = [];
		$env = YII_ENV_PROD? '' : 'test.';
		$remoteHost = PROTOCOL.'://static.'.$env.'wooppay.com/';
		foreach ($attributeNames as $key => $value) {
			if ($model->$key instanceof UploadedFile) {
				if (StaticServerFileSync::moveTo($model->$key, $path)) {
					$downloadResults[$key] = true;
				} else {
					$downloadResults[$key] = false;
				}
			} else {
				$deletePath = str_replace($remoteHost,'', $value);
				if (StaticServerFileSync::remove($deletePath)) {
					$downloadResults[$key] = true;
				} else {
					$downloadResults[$key] = false;
				}
			}
		}
		return $downloadResults;
	}
}
