# Coverage matrix

Each symbol names the test that constructs or calls it.

Coverage labels:

- **behavioral** — test changes and asserts site/archive state (export → import, guards, roundtrips).
- **wired** — container resolve / `instanceof` / smoke only; does **not** prove pipeline behavior.

Empty stub `Chunking/ChunkController.php` is excluded.

| File | Symbol | Coverage |
|------|--------|----------|
| `Admin/AdminPageController.php` | `AdminPageController` | wired:MissingPipelineTest (service container resolves AdminPageController) |
| `Admin/Handlers/ExportRequestHandler.php` | `ExportRequestHandler` | integration:MissingPipelineTest |
| `Admin/Handlers/ImportRequestHandler.php` | `ImportRequestHandler` | integration:MissingPipelineTest |
| `Admin/Handlers/ThemePreviewRequestHandler.php` | `ThemePreviewRequestHandler` | integration:MissingPipelineTest |
| `Admin/Handlers/UserMergeRequestHandler.php` | `UserMergeRequestHandler` | integration:MissingPipelineTest |
| `Admin/Services/ContentPickerQueryService.php` | `ContentPickerQueryService` | integration:MissingPipelineTest |
| `Admin/Services/FullSiteImportService.php` | `FullSiteImportService` | wired:MissingPipelineTest (service container resolves FullSiteImportService) |
| `Admin/Services/ImportFileValidator.php` | `ImportFileValidator` | wired:MissingPipelineTest (service container resolves ImportFileValidator) |
| `Admin/Services/ImportPayloadPreparer.php` | `ImportPayloadPreparer` | behavioral:SelectedContentExportImportTest, UnifiedImportFlowTest; wired:MissingPipelineTest (service container resolves ImportPayloadPreparer) |
| `Admin/Services/ImportPreflightService.php` | `ImportPreflightService` | behavioral:UnifiedImportFlowTest; integration:MissingPipelineTest |
| `Admin/Services/ImportTypeDetector.php` | `ImportTypeDetector` | behavioral:UnifiedImportFlowTest, ThemeArchiveRoundtripTest; unit:ImportTypeDetectorTest |
| `Admin/Services/NotificationService.php` | `NotificationService` | wired:MissingPipelineTest (service container resolves NotificationService) |
| `Admin/Services/PreflightReportStore.php` | `PreflightReportStore` | behavioral:UnifiedImportFlowTest, PreflightArtifactsTest; integration:AdminPreflightTest |
| `Admin/Services/ProgressService.php` | `ProgressService` | wired:MissingPipelineTest (service container resolves ProgressService) |
| `Admin/Services/ResponseHandler.php` | `ResponseHandler` | wired:MissingPipelineTest (service container resolves ResponseHandler) |
| `Admin/Services/SelectedContentImportService.php` | `SelectedContentImportService` | wired:MissingPipelineTest (service container resolves SelectedContentImportService) |
| `Admin/Services/ServerBackupScanner.php` | `ServerBackupScanner` | integration:PreflightArtifactsTest |
| `Admin/Services/ThemeImportService.php` | `ThemeImportService` | wired:MissingPipelineTest (service container resolves ThemeImportService) |
| `Admin/Services/UnifiedImportOrchestrator.php` | `UnifiedImportOrchestrator` | integration:MissingPipelineTest |
| `Admin/Views/AdminPageView.php` | `AdminPageView` | wired:MissingPipelineTest (service container resolves AdminPageView) |
| `Archive/Extractor.php` | `Extractor` | behavioral:SelectedContentExportImportTest; unit:PackerExtractorTest |
| `Archive/Packer.php` | `Packer` | behavioral:SelectedContentExportImportTest; unit:PackerExtractorTest |
| `Chunking/ChunkJob.php` | `ChunkJob` | unit:ChunkJobTest |
| `Chunking/ChunkJobRepository.php` | `ChunkJobRepository` | integration:ChunkRestResumeTest |
| `Chunking/ChunkRestController.php` | `ChunkRestController` | integration:ChunkRestResumeTest, RuntimeGuardsTest |
| `Chunking/ChunkServiceProvider.php` | `ChunkServiceProvider` | integration:MissingPipelineTest |
| `Chunking/FullExportBuilder.php` | `FullExportBuilder` | integration:MissingPipelineTest |
| `Config/PluginConfig.php` | `PluginConfig` | integration:AdminPreflightTest |
| `Contracts/ArchiveHandlerInterface.php` | `ArchiveHandlerInterface` | wired:MissingPipelineTest (service container resolves ArchiveHandlerInterface) |
| `Contracts/ChunkJobRepositoryInterface.php` | `ChunkJobRepositoryInterface` | wired:MissingPipelineTest (service container resolves ChunkJobRepositoryInterface) |
| `Contracts/ExportRequestHandlerInterface.php` | `ExportRequestHandlerInterface` | wired:MissingPipelineTest (service container resolves ExportRequestHandlerInterface) |
| `Contracts/ExporterInterface.php` | `ExporterInterface` | wired:MissingPipelineTest (service container resolves ExporterInterface) |
| `Contracts/ImportRequestHandlerInterface.php` | `ImportRequestHandlerInterface` | wired:MissingPipelineTest (service container resolves ImportRequestHandlerInterface) |
| `Contracts/ImporterInterface.php` | `ImporterInterface` | integration:MissingPipelineTest |
| `Contracts/MediaCollectorInterface.php` | `MediaCollectorInterface` | wired:MissingPipelineTest (service container resolves MediaCollectorInterface) |
| `Contracts/NotificationServiceInterface.php` | `NotificationServiceInterface` | wired:MissingPipelineTest (service container resolves NotificationServiceInterface) |
| `Contracts/ProgressServiceInterface.php` | `ProgressServiceInterface` | wired:MissingPipelineTest (service container resolves ProgressServiceInterface) |
| `Contracts/RequestHandlerInterface.php` | `RequestHandlerInterface` | integration:MissingPipelineTest |
| `Contracts/ThemePreviewRequestHandlerInterface.php` | `ThemePreviewRequestHandlerInterface` | wired:MissingPipelineTest (service container resolves ThemePreviewRequestHandlerInterface) |
| `Contracts/ThemePreviewStoreInterface.php` | `ThemePreviewStoreInterface` | wired:MissingPipelineTest (service container resolves ThemePreviewStoreInterface) |
| `Contracts/UserDiffBuilderInterface.php` | `UserDiffBuilderInterface` | integration:MissingPipelineTest |
| `Contracts/UserMergeApplierInterface.php` | `UserMergeApplierInterface` | integration:MissingPipelineTest |
| `Contracts/UserMergeRequestHandlerInterface.php` | `UserMergeRequestHandlerInterface` | wired:MissingPipelineTest (service container resolves UserMergeRequestHandlerInterface) |
| `Contracts/UserPreviewStoreInterface.php` | `UserPreviewStoreInterface` | wired:MissingPipelineTest (service container resolves UserPreviewStoreInterface) |
| `Contracts/ValidatorInterface.php` | `ValidatorInterface` | integration:MissingPipelineTest |
| `Core/BatchLoader.php` | `BatchLoader` | wired:MissingPipelineTest (service container resolves BatchLoader) |
| `Core/ServiceContainer.php` | `ServiceContainer` | unit:ServiceContainerTest |
| `Core/ServiceContainerFactory.php` | `ServiceContainerFactory` | integration:MissingPipelineTest |
| `Core/ServiceProviderInterface.php` | `ServiceProviderInterface` | integration:MissingPipelineTest |
| `Core/ServiceProviders/AdminServiceProvider.php` | `AdminServiceProvider` | integration:MissingPipelineTest |
| `Core/ServiceProviders/CoreServiceProvider.php` | `CoreServiceProvider` | integration:MissingPipelineTest |
| `Core/ServiceProviders/ExportServiceProvider.php` | `ExportServiceProvider` | integration:MissingPipelineTest |
| `Core/ServiceProviders/ImportServiceProvider.php` | `ImportServiceProvider` | integration:MissingPipelineTest |
| `Core/View/ViewRenderer.php` | `ViewRenderer` | integration:MissingPipelineTest |
| `Core/Wrappers/WpFilesystemWrapper.php` | `WpFilesystemWrapper` | integration:MissingPipelineTest |
| `Core/Wrappers/WpFilesystemWrapperInterface.php` | `WpFilesystemWrapperInterface` | wired:MissingPipelineTest (service container resolves WpFilesystemWrapperInterface) |
| `Core/Wrappers/WpFunctionsWrapper.php` | `WpFunctionsWrapper` | integration:MissingPipelineTest |
| `Core/Wrappers/WpFunctionsWrapperInterface.php` | `WpFunctionsWrapperInterface` | wired:MissingPipelineTest (service container resolves WpFunctionsWrapperInterface) |
| `Core/Wrappers/WpUserFunctionsWrapper.php` | `WpUserFunctionsWrapper` | integration:MissingPipelineTest |
| `Core/Wrappers/WpUserFunctionsWrapperInterface.php` | `WpUserFunctionsWrapperInterface` | wired:MissingPipelineTest (service container resolves WpUserFunctionsWrapperInterface) |
| `DataTransferObjects/ExportRequest.php` | `ExportRequest` | unit:ExportRequestTest |
| `DataTransferObjects/ExportResult.php` | `ExportResult` | unit:ExportResultTest |
| `DataTransferObjects/FileUpload.php` | `FileUpload` | unit:FileUploadTest |
| `DataTransferObjects/ImportRequest.php` | `ImportRequest` | unit:ImportRequestTest |
| `Database/FullDatabaseExporter.php` | `FullDatabaseExporter` | integration:FullSiteSubsetTest, FullDatabaseRoundtripTest |
| `Database/FullDatabaseImporter.php` | `FullDatabaseImporter` | integration:MissingPipelineTest, FullDatabaseRoundtripTest |
| `Database/SwapTableNames.php` | `SwapTableNames` | unit:SwapTableNamesTest |
| `Exceptions/DatabaseOperationException.php` | `DatabaseOperationException` | unit:ExceptionsTest |
| `Exceptions/ExportException.php` | `ExportException` | unit:ExceptionsTest |
| `Exceptions/FileOperationException.php` | `FileOperationException` | unit:ExceptionsTest |
| `Exceptions/ImportException.php` | `ImportException` | unit:ExceptionsTest |
| `Exceptions/ValidationException.php` | `ValidationException` | unit:ExceptionsTest |
| `Export/ExportHandler.php` | `ExportHandler` | behavioral:SelectedContentExportImportTest, UnifiedImportFlowTest; integration:MissingPipelineTest |
| `Filesystem/ContentCollector.php` | `ContentCollector` | integration:FullFilesystemRoundtripTest |
| `Filesystem/FullArchivePayload.php` | `FullArchivePayload` | integration:MissingPipelineTest |
| `Filesystem/FullContentExporter.php` | `FullContentExporter` | behavioral:FullSiteExportImportTest, UnifiedImportFlowTest; wired:MissingPipelineTest |
| `Filesystem/FullContentImporter.php` | `FullContentImporter` | behavioral:FullSiteExportImportTest, FullFilesystemRoundtripTest |
| `Filesystem/ThemeExporter.php` | `ThemeExporter` | behavioral:ThemeArchiveRoundtripTest, ThemeExportImportGapsTest, UnifiedImportFlowTest |
| `Filesystem/ThemeFileDiffBuilder.php` | `ThemeFileDiffBuilder` | unit:ThemeFileDiffBuilderTest, integration:ThemeArchiveRoundtripTest |
| `Filesystem/ThemeImporter.php` | `ThemeImporter` | behavioral:ThemeArchiveRoundtripTest, ThemeExportImportGapsTest |
| `Import/ImportHandler.php` | `ImportHandler` | behavioral:SelectedContentExportImportTest, UnifiedImportFlowTest, SelectedContentRoundtripTest, SelectedContentMediaTest |
| `Import/SelectedContentDiffBuilder.php` | `SelectedContentDiffBuilder` | behavioral:SelectedContentDiffBuilderTest; integration:MissingPipelineTest |
| `Media/AttachmentCollection.php` | `AttachmentCollection` | unit:AttachmentCollectionTest |
| `Media/AttachmentCollector.php` | `AttachmentCollector` | behavioral:SelectedContentExportImportTest (gallery + meta IDs); wired:MissingPipelineTest |
| `Media/AttachmentRestorer.php` | `AttachmentRestorer` | behavioral:SelectedContentExportImportTest, SelectedContentMediaTest |
| `Options/OptionsExporter.php` | `OptionsExporter` | wired:MissingPipelineTest (service container resolves OptionsExporter) |
| `Options/OptionsHelper.php` | `OptionsHelper` | wired:MissingPipelineTest (service container resolves OptionsHelper) |
| `Options/OptionsImporter.php` | `OptionsImporter` | integration:SelectedContentMediaTest |
| `Plugin.php` | `Plugin` | unit:SmokeTest |
| `Selection/ContentSelection.php` | `ContentSelection` | unit:ContentSelectionTest |
| `Selection/SelectionBuilder.php` | `SelectionBuilder` | unit:SelectionBuilderTest |
| `Services/ErrorHandler.php` | `ErrorHandler` | wired:MissingPipelineTest (service container resolves ErrorHandler) |
| `Services/PluginLogger.php` | `PluginLogger` | integration:MissingPipelineTest |
| `Support/DeactivationCleanup.php` | `DeactivationCleanup` | integration:AdminPreflightTest, PreflightArtifactsTest |
| `Support/DomainReplacer.php` | `DomainReplacer` | unit:DomainReplacerTest; behavioral:FullSiteExportImportTest, FullSiteSubsetTest |
| `Support/EnvironmentVersionComparator.php` | `EnvironmentVersionComparator` | unit:EnvironmentVersionComparatorTest |
| `Support/ExportMemoryHelper.php` | `ExportMemoryHelper` | integration:MissingPipelineTest |
| `Support/ExportPreflight.php` | `ExportPreflight` | integration:FullSiteSubsetTest, RuntimeGuardsTest |
| `Support/FilenameBuilder.php` | `FilenameBuilder` | unit:FilenameBuilderTest |
| `Support/FilesystemHelper.php` | `FilesystemHelper` | unit:ChunkJobTest |
| `Support/FullImportMaintenance.php` | `FullImportMaintenance` | integration:RuntimeGuardsTest, MissingPipelineTest |
| `Support/ImportArtifactCleanup.php` | `ImportArtifactCleanup` | integration:PreflightArtifactsTest, MissingPipelineTest |
| `Support/ImportLock.php` | `ImportLock` | integration:FullSiteSubsetTest, RuntimeGuardsTest |
| `Support/MimeTypeHelper.php` | `MimeTypeHelper` | unit:MimeTypeHelperTest |
| `Support/PostImportMaintenance.php` | `PostImportMaintenance` | integration:MissingPipelineTest |
| `Support/PreflightStagingPath.php` | `PreflightStagingPath` | unit:PreflightStagingPathTest |
| `Support/SiteUrlGuard.php` | `SiteUrlGuard` | integration:RuntimeGuardsTest |
| `Support/ThemeArchivePathHelper.php` | `ThemeArchivePathHelper` | unit:ThemeArchivePathHelperTest |
| `Support/WpContentRuntimeStorage.php` | `WpContentRuntimeStorage` | integration:MissingPipelineTest |
| `Themes/ThemePreviewStore.php` | `ThemePreviewStore` | behavioral:ThemeExportImportGapsTest; integration:MissingPipelineTest |
| `Users/UserDiffBuilder.php` | `UserDiffBuilder` | integration:UserMergeTest |
| `Users/UserMergeApplier.php` | `UserMergeApplier` | integration:UserMergeTest |
| `Users/UserPreviewStore.php` | `UserPreviewStore` | integration:UserMergeTest |
| `Validation/ArchiveValidator.php` | `ArchiveValidator` | unit:ArchiveValidatorTest |
| `Validation/ExportDataValidator.php` | `ExportDataValidator` | unit:ExportDataValidatorTest |
| `Validation/FileValidator.php` | `FileValidator` | integration:MissingPipelineTest |
| `Validation/ImportDataValidator.php` | `ImportDataValidator` | unit:ImportDataValidatorTest |
| `Validation/ValidationResult.php` | `ValidationResult` | unit:ValidationResultTest |

## Required edge cases

| Edge case | Test |
|-----------|------|
| Serialized URL/path replace | DomainReplacerTest |
| Nested serialized + object + port/subdir URL replace | DomainReplacerTest |
| Broken serialized str_replace characterization | DomainReplacerTest |
| FullContentImporter URL + uploads path rewrite | FullSiteExportImportTest |
| Full-site zip-slip path rejection | FullSiteExportImportTest |
| Full-site theme backup restore on extract failure | FullSiteExportImportTest |
| Plugin + mu-plugin body roundtrip | FullSiteExportImportTest |
| Exact option_value after DomainReplacer | FullSiteSubsetTest |
| Selected core fields (excerpt/author/status/menu_order) | SelectedContentExportImportTest |
| Selected tax exact set + upsert replace | SelectedContentExportImportTest |
| Selected media on-disk hash_file | SelectedContentExportImportTest, SelectedContentMediaTest |
| Selected gallery + meta-only attachment IDs | SelectedContentExportImportTest |
| Gallery shortcode ID remap in AttachmentRestorer | SelectedContentExportImportTest |
| Selected featured overwrite on upsert | SelectedContentExportImportTest |
| SelectedContentDiffBuilder title change / identical | SelectedContentDiffBuilderTest |
| ImportLock wrong token keeps lock | RuntimeGuardsTest |
| SiteUrlGuard restores saved siteurl/home | RuntimeGuardsTest |
| Detector on real FullContentExporter archive | UnifiedImportFlowTest |
| Theme merge overwrites overlapping style.css | ThemeArchiveRoundtripTest |
| Slug parent import order | SelectedContentRoundtripTest |
| Export payload slug and parent | MissingPipelineTest |
| Archive checksum mismatch | PackerExtractorTest |
| Swap table names | SwapTableNamesTest |
| Importer skips swap tables | MissingPipelineTest |
| Chunk status CAS | ChunkJobTest |
| Chunk download resume | MissingPipelineTest |
| Preflight path allowlist | PreflightStagingPathTest |
| Preflight analyze and claim | MissingPipelineTest |
| Capability and nonce rejection | MissingPipelineTest |
| Custom-table prefix rewrite | FullDatabaseRoundtripTest |
| Schema recreate replaces rows | FullDatabaseRoundtripTest |
| Leftover `_mkn` drop / `_mko` recover | FullDatabaseRoundtripTest |
| Insert failure after schema | FullDatabaseRoundtripTest |
| Full-site FS-only uploads probe | FullFilesystemRoundtripTest |
| ContentCollector skips runtime paths | FullFilesystemRoundtripTest |
| Selected media checksum + featured | SelectedContentMediaTest |
| Options overwrite=false | SelectedContentMediaTest |
| Theme replace vs merge | ThemeArchiveRoundtripTest |
| ThemeFileDiffBuilder will_delete | ThemeArchiveRoundtripTest |
| User merge create/replace/keep | UserMergeTest |
| UserDiff conflict vs new | UserMergeTest |
| Preflight claim busy/blocked/release | PreflightArtifactsTest |
| Artifact stage/persist/discard | PreflightArtifactsTest |
| ServerBackupScanner path guard | PreflightArtifactsTest |
| Deactivation keeps imports | PreflightArtifactsTest |
| Chunk cancel pending vs ready | RuntimeGuardsTest |
| Stale import lock | RuntimeGuardsTest |
| FullImportMaintenance activate | RuntimeGuardsTest |
| Selected export→import parent/upsert/CPT/tax/meta | SelectedContentExportImportTest |
| Selected media-from-zip (Packer archive) | SelectedContentExportImportTest |
| Selected checksum mismatch on Packer archive | SelectedContentExportImportTest |
| Selected JSON export→import | SelectedContentExportImportTest |
| Preflight claim→import same staged path | UnifiedImportFlowTest |
| Full exporter archive shape (probe table+upload+plugin) | FullSiteExportImportTest |
| Full importer probe DB+FS together | FullSiteExportImportTest |
| User merge via FullContentImporter (users-only dump) | FullSiteExportImportTest |
| Theme multi-theme export→import | ThemeExportImportGapsTest |
| Theme preview store then replace/merge | ThemeExportImportGapsTest |
| Theme child path layout | ThemeExportImportGapsTest |
