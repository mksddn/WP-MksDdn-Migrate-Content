# Coverage matrix

Each symbol names the test that constructs or calls it. A service-container row means `MissingPipelineTest` resolves that binding through `ServiceContainerFactory::create()`.

Empty stub `Chunking/ChunkController.php` is excluded.

| File | Symbol | Coverage |
|------|--------|----------|
| `Admin/AdminPageController.php` | `AdminPageController` | integration:MissingPipelineTest (service container resolves AdminPageController) |
| `Admin/Handlers/ExportRequestHandler.php` | `ExportRequestHandler` | integration:MissingPipelineTest |
| `Admin/Handlers/ImportRequestHandler.php` | `ImportRequestHandler` | integration:MissingPipelineTest |
| `Admin/Handlers/ThemePreviewRequestHandler.php` | `ThemePreviewRequestHandler` | integration:MissingPipelineTest |
| `Admin/Handlers/UserMergeRequestHandler.php` | `UserMergeRequestHandler` | integration:MissingPipelineTest |
| `Admin/Services/ContentPickerQueryService.php` | `ContentPickerQueryService` | integration:MissingPipelineTest |
| `Admin/Services/FullSiteImportService.php` | `FullSiteImportService` | integration:MissingPipelineTest (service container resolves FullSiteImportService) |
| `Admin/Services/ImportFileValidator.php` | `ImportFileValidator` | integration:MissingPipelineTest (service container resolves ImportFileValidator) |
| `Admin/Services/ImportPayloadPreparer.php` | `ImportPayloadPreparer` | integration:MissingPipelineTest (service container resolves ImportPayloadPreparer) |
| `Admin/Services/ImportPreflightService.php` | `ImportPreflightService` | integration:MissingPipelineTest |
| `Admin/Services/ImportTypeDetector.php` | `ImportTypeDetector` | unit:ImportTypeDetectorTest |
| `Admin/Services/NotificationService.php` | `NotificationService` | integration:MissingPipelineTest (service container resolves NotificationService) |
| `Admin/Services/PreflightReportStore.php` | `PreflightReportStore` | integration:AdminPreflightTest, PreflightArtifactsTest |
| `Admin/Services/ProgressService.php` | `ProgressService` | integration:MissingPipelineTest (service container resolves ProgressService) |
| `Admin/Services/ResponseHandler.php` | `ResponseHandler` | integration:MissingPipelineTest (service container resolves ResponseHandler) |
| `Admin/Services/SelectedContentImportService.php` | `SelectedContentImportService` | integration:MissingPipelineTest (service container resolves SelectedContentImportService) |
| `Admin/Services/ServerBackupScanner.php` | `ServerBackupScanner` | integration:PreflightArtifactsTest |
| `Admin/Services/ThemeImportService.php` | `ThemeImportService` | integration:MissingPipelineTest (service container resolves ThemeImportService) |
| `Admin/Services/UnifiedImportOrchestrator.php` | `UnifiedImportOrchestrator` | integration:MissingPipelineTest |
| `Admin/Views/AdminPageView.php` | `AdminPageView` | integration:MissingPipelineTest (service container resolves AdminPageView) |
| `Archive/Extractor.php` | `Extractor` | unit:PackerExtractorTest |
| `Archive/Packer.php` | `Packer` | unit:PackerExtractorTest |
| `Chunking/ChunkJob.php` | `ChunkJob` | unit:ChunkJobTest |
| `Chunking/ChunkJobRepository.php` | `ChunkJobRepository` | integration:ChunkRestResumeTest |
| `Chunking/ChunkRestController.php` | `ChunkRestController` | integration:ChunkRestResumeTest, RuntimeGuardsTest |
| `Chunking/ChunkServiceProvider.php` | `ChunkServiceProvider` | integration:MissingPipelineTest |
| `Chunking/FullExportBuilder.php` | `FullExportBuilder` | integration:MissingPipelineTest |
| `Config/PluginConfig.php` | `PluginConfig` | integration:AdminPreflightTest |
| `Contracts/ArchiveHandlerInterface.php` | `ArchiveHandlerInterface` | integration:MissingPipelineTest (service container resolves ArchiveHandlerInterface) |
| `Contracts/ChunkJobRepositoryInterface.php` | `ChunkJobRepositoryInterface` | integration:MissingPipelineTest (service container resolves ChunkJobRepositoryInterface) |
| `Contracts/ExportRequestHandlerInterface.php` | `ExportRequestHandlerInterface` | integration:MissingPipelineTest (service container resolves ExportRequestHandlerInterface) |
| `Contracts/ExporterInterface.php` | `ExporterInterface` | integration:MissingPipelineTest (service container resolves ExporterInterface) |
| `Contracts/ImportRequestHandlerInterface.php` | `ImportRequestHandlerInterface` | integration:MissingPipelineTest (service container resolves ImportRequestHandlerInterface) |
| `Contracts/ImporterInterface.php` | `ImporterInterface` | integration:MissingPipelineTest |
| `Contracts/MediaCollectorInterface.php` | `MediaCollectorInterface` | integration:MissingPipelineTest (service container resolves MediaCollectorInterface) |
| `Contracts/NotificationServiceInterface.php` | `NotificationServiceInterface` | integration:MissingPipelineTest (service container resolves NotificationServiceInterface) |
| `Contracts/ProgressServiceInterface.php` | `ProgressServiceInterface` | integration:MissingPipelineTest (service container resolves ProgressServiceInterface) |
| `Contracts/RequestHandlerInterface.php` | `RequestHandlerInterface` | integration:MissingPipelineTest |
| `Contracts/ThemePreviewRequestHandlerInterface.php` | `ThemePreviewRequestHandlerInterface` | integration:MissingPipelineTest (service container resolves ThemePreviewRequestHandlerInterface) |
| `Contracts/ThemePreviewStoreInterface.php` | `ThemePreviewStoreInterface` | integration:MissingPipelineTest (service container resolves ThemePreviewStoreInterface) |
| `Contracts/UserDiffBuilderInterface.php` | `UserDiffBuilderInterface` | integration:MissingPipelineTest |
| `Contracts/UserMergeApplierInterface.php` | `UserMergeApplierInterface` | integration:MissingPipelineTest |
| `Contracts/UserMergeRequestHandlerInterface.php` | `UserMergeRequestHandlerInterface` | integration:MissingPipelineTest (service container resolves UserMergeRequestHandlerInterface) |
| `Contracts/UserPreviewStoreInterface.php` | `UserPreviewStoreInterface` | integration:MissingPipelineTest (service container resolves UserPreviewStoreInterface) |
| `Contracts/ValidatorInterface.php` | `ValidatorInterface` | integration:MissingPipelineTest |
| `Core/BatchLoader.php` | `BatchLoader` | integration:MissingPipelineTest (service container resolves BatchLoader) |
| `Core/ServiceContainer.php` | `ServiceContainer` | unit:ServiceContainerTest |
| `Core/ServiceContainerFactory.php` | `ServiceContainerFactory` | integration:MissingPipelineTest |
| `Core/ServiceProviderInterface.php` | `ServiceProviderInterface` | integration:MissingPipelineTest |
| `Core/ServiceProviders/AdminServiceProvider.php` | `AdminServiceProvider` | integration:MissingPipelineTest |
| `Core/ServiceProviders/CoreServiceProvider.php` | `CoreServiceProvider` | integration:MissingPipelineTest |
| `Core/ServiceProviders/ExportServiceProvider.php` | `ExportServiceProvider` | integration:MissingPipelineTest |
| `Core/ServiceProviders/ImportServiceProvider.php` | `ImportServiceProvider` | integration:MissingPipelineTest |
| `Core/View/ViewRenderer.php` | `ViewRenderer` | integration:MissingPipelineTest |
| `Core/Wrappers/WpFilesystemWrapper.php` | `WpFilesystemWrapper` | integration:MissingPipelineTest |
| `Core/Wrappers/WpFilesystemWrapperInterface.php` | `WpFilesystemWrapperInterface` | integration:MissingPipelineTest (service container resolves WpFilesystemWrapperInterface) |
| `Core/Wrappers/WpFunctionsWrapper.php` | `WpFunctionsWrapper` | integration:MissingPipelineTest |
| `Core/Wrappers/WpFunctionsWrapperInterface.php` | `WpFunctionsWrapperInterface` | integration:MissingPipelineTest (service container resolves WpFunctionsWrapperInterface) |
| `Core/Wrappers/WpUserFunctionsWrapper.php` | `WpUserFunctionsWrapper` | integration:MissingPipelineTest |
| `Core/Wrappers/WpUserFunctionsWrapperInterface.php` | `WpUserFunctionsWrapperInterface` | integration:MissingPipelineTest (service container resolves WpUserFunctionsWrapperInterface) |
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
| `Export/ExportHandler.php` | `ExportHandler` | integration:MissingPipelineTest |
| `Filesystem/ContentCollector.php` | `ContentCollector` | integration:FullFilesystemRoundtripTest |
| `Filesystem/FullArchivePayload.php` | `FullArchivePayload` | integration:MissingPipelineTest |
| `Filesystem/FullContentExporter.php` | `FullContentExporter` | integration:MissingPipelineTest |
| `Filesystem/FullContentImporter.php` | `FullContentImporter` | integration:MissingPipelineTest, FullFilesystemRoundtripTest |
| `Filesystem/ThemeExporter.php` | `ThemeExporter` | integration:ThemeArchiveRoundtripTest |
| `Filesystem/ThemeFileDiffBuilder.php` | `ThemeFileDiffBuilder` | unit:ThemeFileDiffBuilderTest, integration:ThemeArchiveRoundtripTest |
| `Filesystem/ThemeImporter.php` | `ThemeImporter` | integration:ThemeArchiveRoundtripTest |
| `Import/ImportHandler.php` | `ImportHandler` | integration:SelectedContentRoundtripTest, SelectedContentMediaTest |
| `Import/SelectedContentDiffBuilder.php` | `SelectedContentDiffBuilder` | integration:MissingPipelineTest |
| `Media/AttachmentCollection.php` | `AttachmentCollection` | unit:AttachmentCollectionTest |
| `Media/AttachmentCollector.php` | `AttachmentCollector` | integration:MissingPipelineTest (service container resolves AttachmentCollector) |
| `Media/AttachmentRestorer.php` | `AttachmentRestorer` | integration:SelectedContentMediaTest |
| `Options/OptionsExporter.php` | `OptionsExporter` | integration:MissingPipelineTest (service container resolves OptionsExporter) |
| `Options/OptionsHelper.php` | `OptionsHelper` | integration:MissingPipelineTest (service container resolves OptionsHelper) |
| `Options/OptionsImporter.php` | `OptionsImporter` | integration:SelectedContentMediaTest |
| `Plugin.php` | `Plugin` | unit:SmokeTest |
| `Selection/ContentSelection.php` | `ContentSelection` | unit:ContentSelectionTest |
| `Selection/SelectionBuilder.php` | `SelectionBuilder` | unit:SelectionBuilderTest |
| `Services/ErrorHandler.php` | `ErrorHandler` | integration:MissingPipelineTest (service container resolves ErrorHandler) |
| `Services/PluginLogger.php` | `PluginLogger` | integration:MissingPipelineTest |
| `Support/DeactivationCleanup.php` | `DeactivationCleanup` | integration:AdminPreflightTest, PreflightArtifactsTest |
| `Support/DomainReplacer.php` | `DomainReplacer` | unit:DomainReplacerTest |
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
| `Themes/ThemePreviewStore.php` | `ThemePreviewStore` | integration:MissingPipelineTest |
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
