<?php

namespace PhpIntegrator;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;

use Doctrine\DBAL\Exception\TableNotFoundException;

use PhpIntegrator\IndexDataProvider;

class IndexDatabase implements
    Indexer\StorageInterface,
    IndexDataAdapter\ProviderInterface
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * The path to the database to use.
     *
     * @var string
     */
    protected $databasePath;

    /**
     * The version of the database index.
     *
     * @var string
     */
    protected $databaseVersion;

    /**
     * Constructor.
     *
     * @param string $databasePath
     * @param int    $databaseVersion
     */
    public function __construct($databasePath, $databaseVersion)
    {
        $this->databasePath = $databasePath;
        $this->databaseVersion = $databaseVersion;

        // Force establishing the connection and creation of tables.
        $this->getConnection();
    }

    /**
     * Retrieves hte index database.
     *
     * @return Connection
     */
    public function getConnection()
    {
        if (!$this->connection) {
            $isNewDatabase = !file_exists($this->databasePath);

            $configuration = new Configuration();

            $this->connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path'   => $this->databasePath
            ], $configuration);

            $outOfDate = null;

            if ($isNewDatabase) {
                $this->createDatabaseTables($this->connection);

                // NOTE: This causes a database write and will cause locking problems if multiple PHP processes are
                // spawned and another one is also writing (e.g. indexing).
                $this->connection->executeQuery('PRAGMA user_version=' . $this->databaseVersion);
            } else {
                $version = $this->connection->executeQuery('PRAGMA user_version')->fetchColumn();

                if ($version < $this->databaseVersion) {
                    $this->connection->close();
                    $this->connection = null;

                    @unlink($this->databasePath);

                    return $this->getConnection(); // Do it again.
                }
            }
        }

        // Have to be a douche about this as these PRAGMA's seem to reset, even though the connection is not closed.
        $this->connection->executeQuery('PRAGMA foreign_keys=ON');

        // Data could become corrupted if the operating system were to crash during synchronization, but this
        // matters very little as we will just reindex the project next time. In the meantime, this majorly reduces
        // hard disk I/O during indexing and increases indexing speed dramatically (dropped from over a minute to a
        // couple of seconds for a very small (!) project).
        $this->connection->executeQuery('PRAGMA synchronous=OFF');

        return $this->connection;
    }

    /**
     * (Re)creates the database tables in the database using the specified connection.
     *
     * @param Connection $connection
     */
    protected function createDatabaseTables(Connection $connection)
    {
        $files = glob(__DIR__ . '/Sql/*.sql');

        foreach ($files as $file) {
            $sql = file_get_contents($file);

            foreach (explode(';', $sql) as $sqlQuery) {
                $statement = $connection->prepare($sqlQuery);
                $statement->execute();
            }
        }

        $connection->insert(IndexStorageItemEnum::SETTINGS, [
            'name'  => 'version',
            'value' => $this->databaseVersion
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getFileModifiedMap()
    {
        $result = $this->getConnection()->createQueryBuilder()
            ->select('path', 'indexed_time')
            ->from(IndexStorageItemEnum::FILES)
            ->execute();

        $files = [];

        foreach ($result as $record) {
            $files[$record['path']] = new \DateTime($record['indexed_time']);
        }

        return $files;
    }

    /**
     * {@inheritDoc}
     */
    public function getFileId($path)
    {
        $result = $this->getConnection()->createQueryBuilder()
            ->select('id')
            ->from(IndexStorageItemEnum::FILES)
            ->where('path = ?')
            ->setParameter(0, $path)
            ->execute()
            ->fetchColumn();

        return $result ? $result : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getAccessModifierMap()
    {
        $result = $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from(IndexStorageItemEnum::ACCESS_MODIFIERS)
            ->execute();

        $map = [];

        foreach ($result as $row) {
            $map[$row['name']] = $row['id'];
        }

        return $map;
    }

    /**
     * {@inheritDoc}
     */
    public function getStructuralElementTypeMap()
    {
        $result = $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from(IndexStorageItemEnum::STRUCTURAL_ELEMENT_TYPES)
            ->execute();

        $map = [];

        foreach ($result as $row) {
            $map[$row['name']] = $row['id'];
        }

        return $map;
    }

    /**
     * {@inheritDoc}
     */
    public function getStructuralElementId($fqsen)
    {
        $result = $this->getConnection()->createQueryBuilder()
            ->select('id')
            ->from(IndexStorageItemEnum::STRUCTURAL_ELEMENTS)
            ->where('fqsen = ?')
            ->setParameter(0, $fqsen)
            ->execute()
            ->fetchColumn();

        return $result ? $result : null;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteFile($fileId)
    {
        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::FILES)
            ->where('id = ?')
            ->setParameter(0, $fileId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function deletePropertiesByFileId($fileId)
    {
        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::PROPERTIES)
            ->where('file_id = ?')
            ->setParameter(0, $fileId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteConstantsByFileId($fileId)
    {
        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::CONSTANTS)
            ->where('file_id = ?')
            ->setParameter(0, $fileId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteFunctionsByFileId($fileId)
    {
        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::FUNCTIONS)
            ->where('file_id = ?')
            ->setParameter(0, $fileId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function deletePropertiesFor($seId)
    {
        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::PROPERTIES)
            ->where('structural_element_id = ?')
            ->setParameter(0, $seId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMethodsFor($seId)
    {
        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::FUNCTIONS)
            ->where('structural_element_id = ?')
            ->setParameter(0, $seId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteConstantsFor($seId)
    {
        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::CONSTANTS)
            ->where('structural_element_id = ?')
            ->setParameter(0, $seId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteParentLinksFor($seId)
    {
        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::STRUCTURAL_ELEMENTS_PARENTS_LINKED)
            ->where('structural_element_id = ?')
            ->setParameter(0, $seId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteInterfaceLinksFor($seId)
    {
        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::STRUCTURAL_ELEMENTS_INTERFACES_LINKED)
            ->where('structural_element_id = ?')
            ->setParameter(0, $seId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteTraitLinksFor($seId)
    {
        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::STRUCTURAL_ELEMENTS_TRAITS_LINKED)
            ->where('structural_element_id = ?')
            ->setParameter(0, $seId)
            ->execute();

        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::STRUCTURAL_ELEMENTS_TRAITS_ALIASES)
            ->where('structural_element_id = ?')
            ->setParameter(0, $seId)
            ->execute();

        $this->getConnection()->createQueryBuilder()
            ->delete(IndexStorageItemEnum::STRUCTURAL_ELEMENTS_TRAITS_PRECEDENCES)
            ->where('structural_element_id = ?')
            ->setParameter(0, $seId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteExcludedStructuralElementsByFileId($fileId, array $excludedIds)
    {
        if (empty($excludedIds)) {
            $this->getConnection()->createQueryBuilder()
                ->delete(IndexStorageItemEnum::STRUCTURAL_ELEMENTS)
                ->where('file_id = ?')
                ->setParameter(0, $fileId)
                ->execute();
        } else {
            $queryBuilder = $this->getConnection()->createQueryBuilder();

            $queryBuilder
                ->delete(IndexStorageItemEnum::STRUCTURAL_ELEMENTS)
                ->where(
                    'file_id = ' . $queryBuilder->createNamedParameter($fileId) .
                    ' AND ' .
                    'id NOT IN (' . $queryBuilder->createNamedParameter($excludedIds, Connection::PARAM_INT_ARRAY) . ')'
                )
                ->execute();
        }
    }

    /**
     * Retrieves a query builder that fetches raw information about all structural elements.
     *
     * @param int $id
     *
     * @return Doctrine\DBAL\Query\QueryBuilder
     */
    protected function getStructuralElementRawInfoQueryBuilder()
    {
        return $this->getConnection()->createQueryBuilder()
            ->select(
                'se.*',
                'fi.path',
                '(setype.name) AS type_name',
                'sepl.linked_structural_element_id'
            )
            ->from(IndexStorageItemEnum::STRUCTURAL_ELEMENTS, 'se')
            ->innerJoin('se', IndexStorageItemEnum::STRUCTURAL_ELEMENT_TYPES, 'setype', 'setype.id = se.structural_element_type_id')
            ->leftJoin('se', IndexStorageItemEnum::STRUCTURAL_ELEMENTS_PARENTS_LINKED, 'sepl', 'sepl.structural_element_id = se.id')
            ->leftJoin('se', IndexStorageItemEnum::FILES, 'fi', 'fi.id = se.file_id');
    }

    /**
     * Retrieves raw information about all structural elements.
     *
     * @return \Traversable
     */
    public function getAllStructuralElementsRawInfo()
    {
        return $this->getStructuralElementRawInfoQueryBuilder()->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function getStructuralElementRawInfo($id)
    {
        return $this->getStructuralElementRawInfoQueryBuilder()
            ->where('se.id = ?')
            ->setParameter(0, $id)
            ->execute()
            ->fetch();
    }

    /**
     * {@inheritDoc}
     */
    public function getStructuralElementRawInterfaces($id)
    {
        return $this->getConnection()->createQueryBuilder()
            ->select('se.id')
            ->from(IndexStorageItemEnum::STRUCTURAL_ELEMENTS, 'se')
            ->innerJoin('se', IndexStorageItemEnum::STRUCTURAL_ELEMENTS_INTERFACES_LINKED, 'seil', 'seil.linked_structural_element_id = se.id')
            ->where('seil.structural_element_id = ?')
            ->setParameter(0, $id)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function getStructuralElementRawTraits($id)
    {
        return $this->getConnection()->createQueryBuilder()
            ->select('se.id')
            ->from(IndexStorageItemEnum::STRUCTURAL_ELEMENTS, 'se')
            ->innerJoin('se', IndexStorageItemEnum::STRUCTURAL_ELEMENTS_TRAITS_LINKED, 'setl', 'setl.linked_structural_element_id = se.id')
            ->where('setl.structural_element_id = ?')
            ->setParameter(0, $id)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function getStructuralElementRawConstants($id)
    {
        return $this->getConnection()->createQueryBuilder()
            ->select('c.*', 'fi.path')
            ->from(IndexStorageItemEnum::CONSTANTS, 'c')
            ->leftJoin('c', IndexStorageItemEnum::FILES, 'fi', 'fi.id = c.file_id')
            ->where('structural_element_id = ?')
            ->setParameter(0, $id)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function getStructuralElementRawProperties($id)
    {
        return $this->getConnection()->createQueryBuilder()
            ->select('p.*', 'am.name AS access_modifier')
            ->from(IndexStorageItemEnum::PROPERTIES, 'p')
            ->innerJoin('p', IndexStorageItemEnum::ACCESS_MODIFIERS, 'am', 'am.id = p.access_modifier_id')
            ->where('structural_element_id = ?')
            ->setParameter(0, $id)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function getStructuralElementRawMethods($id)
    {
        return $this->getConnection()->createQueryBuilder()
            ->select('fu.*', 'fi.path', 'am.name AS access_modifier')
            ->from(IndexStorageItemEnum::FUNCTIONS, 'fu')
            ->leftJoin('fu', IndexStorageItemEnum::FILES, 'fi', 'fi.id = fu.file_id')
            ->innerJoin('fu', IndexStorageItemEnum::ACCESS_MODIFIERS, 'am', 'am.id = fu.access_modifier_id')
            ->where('structural_element_id = ?')
            ->setParameter(0, $id)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function getStructuralElementTraitAliasesAssoc($id)
    {
        $result = $this->getConnection()->createQueryBuilder()
            ->select('seta.*', 'se.fqsen AS trait_fqsen', 'am.name AS access_modifier')
            ->from(IndexStorageItemEnum::STRUCTURAL_ELEMENTS_TRAITS_ALIASES, 'seta')
            ->leftJoin('seta', IndexStorageItemEnum::ACCESS_MODIFIERS, 'am', 'am.id = seta.access_modifier_id')
            ->leftJoin('seta', IndexStorageItemEnum::STRUCTURAL_ELEMENTS, 'se', 'se.id = seta.trait_structural_element_id')
            ->where('structural_element_id = ?')
            ->setParameter(0, $id)
            ->execute();

        $aliases = [];

        foreach ($result as $row) {
            $aliases[$row['name']] = $row;
        }

        return $aliases;
    }

    /**
     * {@inheritDoc}
     */
    public function getStructuralElementTraitPrecedencesAssoc($id)
    {
        $result = $this->getConnection()->createQueryBuilder()
            ->select('setp.*', 'se.fqsen AS trait_fqsen')
            ->from(IndexStorageItemEnum::STRUCTURAL_ELEMENTS_TRAITS_PRECEDENCES, 'setp')
            ->innerJoin('setp', IndexStorageItemEnum::STRUCTURAL_ELEMENTS, 'se', 'se.id = setp.trait_structural_element_id')
            ->where('structural_element_id = ?')
            ->setParameter(0, $id)
            ->execute();

        $precedences = [];

        foreach ($result as $row) {
            $precedences[$row['name']] = $row;
        }

        return $precedences;
    }

    /**
     * {@inheritDoc}
     */
    public function getParentFqsens($seId)
    {
        $result = [];

        $parentSes = $this->getConnection()->createQueryBuilder()
            ->select('se.id', 'se.fqsen')
            ->from(IndexStorageItemEnum::STRUCTURAL_ELEMENTS, 'se')
            ->innerJoin('se', IndexStorageItemEnum::STRUCTURAL_ELEMENTS_PARENTS_LINKED, 'sepl', 'sepl.linked_structural_element_id = se.id')
            ->where('sepl.structural_element_id = ?')
            ->setParameter(0, $seId)
            ->execute()
            ->fetchAll();

        foreach ($parentSes as $parentSe) {
            $result[$parentSe['id']] = $parentSe['fqsen'];
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getFunctionParameters($functionId)
    {
        return $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from(IndexStorageItemEnum::FUNCTIONS_PARAMETERS)
            ->where('function_id = ?')
            ->setParameter(0, $functionId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function getFunctionThrows($functionId)
    {
        return $this->getConnection()->createQueryBuilder()
            ->select('*')
            ->from(IndexStorageItemEnum::FUNCTIONS_THROWS)
            ->where('function_id = ?')
            ->setParameter(0, $functionId)
            ->execute();
    }

    /**
     * {@inheritDoc}
     */
    public function insert($indexStorageItem, array $data)
    {
        $this->getConnection()->insert($indexStorageItem, $data);

        return $this->getConnection()->lastInsertId();
    }

    /**
     * {@inheritDoc}
     */
    public function update($indexStorageItem, $id, array $data)
    {
        $this->getConnection()->update($indexStorageItem, $data, is_array($id) ? $id : ['id' => $id]);
    }

    /**
     * Fetches a list of global constants.
     *
     * @return \Traversable
     */
    public function getGlobalConstants()
    {
        return $this->getConnection()->createQueryBuilder()
            ->select('c.*', 'fi.path')
            ->from(IndexStorageItemEnum::CONSTANTS, 'c')
            ->leftJoin('c', IndexStorageItemEnum::FILES, 'fi', 'fi.id = c.file_id')
            ->where('structural_element_id IS NULL')
            ->execute();
    }

    /**
     * Fetches a list of global functions.
     *
     * @return \Traversable
     */
    public function getGlobalFunctions()
    {
        return $this->getConnection()->createQueryBuilder()
            ->select('fu.*', 'fi.path')
            ->from(IndexStorageItemEnum::FUNCTIONS, 'fu')
            ->leftJoin('fu', IndexStorageItemEnum::FILES, 'fi', 'fi.id = fu.file_id')
            ->where('structural_element_id IS NULL')
            ->execute();
    }
}
