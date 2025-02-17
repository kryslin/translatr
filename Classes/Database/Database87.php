<?php

namespace SourceBroker\Translatr\Database;

use Doctrine\DBAL\ParameterType;
use SourceBroker\Translatr\Domain\Model\Dto\BeLabelDemand;
use SourceBroker\Translatr\Utility\ArrayUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Database87 implements Database
{
    public function update($table, array $set, array $condition)
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder
            ->update($table);

        foreach ($set as $key => $value) {
            $queryBuilder
                ->set($key, $value);
        }

        foreach ($condition as $key => $value) {
            $queryBuilder
                ->andWhere(sprintf('%s = :%s', $key, $key))
                ->setParameter($key, $value);
        }

        $queryBuilder->execute();
    }

    public function getRootPage()
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        return $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', 0),
                $queryBuilder->expr()->eq('deleted', 0)
            )->execute()->fetch();
    }

    public function findDemandedForBe(BeLabelDemand $demand)
    {
        if (!$demand->isValid()) {
            return [];
        }

        $query = <<<SQL
/* select labels from default language */
(
SELECT 
  label.uid,
  label.language,
  label.ukey,
  0 AS parent_uid,
  label.text,
  label.ll_file
FROM tx_translatr_domain_model_label AS label
WHERE label.language = "default" 
  AND label.deleted = 0
  AND label.extension = ?
) UNION (
/* select labels for specified languages */ 
SELECT  
  label.uid,
  label.language,
  label.ukey,
  parent.uid AS parent_uid,
  label.text,
  label.ll_file
FROM tx_translatr_domain_model_label AS label 
  LEFT JOIN tx_translatr_domain_model_label AS parent
    ON (parent.language = "default" AND parent.ukey = label.ukey AND parent.ll_file = label.ll_file)
WHERE label.language IN (?)  
  AND label.deleted = 0
  AND parent.deleted = 0
  AND parent.extension = ?
);
SQL;
        /** @var Connection $connection */
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName('Default');
        $stmt = $connection->executeQuery(
            $query,
            [
                $demand->getExtension(),
                implode(',', $demand->getLanguages() ?: ['default']),
                $demand->getExtension()
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ]
        );

        $resultAssoc = $stmt->fetchAll();
        $results = ArrayUtility::combineWithSubarrayFieldAsKey(
            $resultAssoc,
            'uid'
        );

        $processedResults = [];

        foreach ($results as &$result) {
            $uid = (int)$result['uid'];
            $parentUid = (int)$result['parent_uid'];
            $language = $result['language'];

            if ($language === 'default') {
                // record in default language are treated as parents
                $processedResults[$uid] = $result;
                $processedResults[$uid]['language_childs'] = [];
            } elseif ($parentUid > 0) {
                // add as a child to parent record
                $processedResults[$parentUid]['language_childs'][$language]
                    = $result;
            }
        }

        return $processedResults;
    }

    public function getLabelsByLocallangFile($locallangFile)
    {
        /** @var Connection $connection */
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName('Default');
        $query = <<<SQL
/* select labels from default language */
SELECT 
  label.text,
  IF (label.ukey IS NOT NULL AND label.ukey != "", label.ukey, parent.ukey) AS ukey,
  IF (label.extension IS NOT NULL AND label.extension != "", label.extension, parent.extension) AS extension,
  label.language AS isocode
FROM tx_translatr_domain_model_label AS label
LEFT JOIN sys_language AS lang ON (
    label.sys_language_uid = lang.uid
)
LEFT JOIN tx_translatr_domain_model_label AS parent ON (
    label.l10n_parent = parent.uid
)
WHERE label.deleted = 0 
  AND label.hidden = 0
  AND (
    label.ll_file = ? 
    OR parent.ll_file = ?
  )
;
SQL;
        $stmt = $connection->executeQuery(
            $query,
            [
                $locallangFile,
                $locallangFile
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING
            ]
        );
        return $stmt->fetchAll();
    }

    public function getLocallanfFiles()
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        return $queryBuilder
            ->select('label.ll_file', 'label.language')
            ->from('tx_translatr_domain_model_label', 'label')
            ->groupBy('label.ll_file')
            ->execute()->fetchAll();
    }
}
