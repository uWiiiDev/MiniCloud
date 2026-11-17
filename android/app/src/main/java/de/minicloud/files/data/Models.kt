package de.minicloud.files.data

data class User(
    val id: Int,
    val username: String,
    val role: String,
    val isAdmin: Boolean
)

data class Quota(
    val used: Long,
    val quota: Long,
    val free: Long,
    val percent: Double,
    val usedFormatted: String,
    val quotaFormatted: String,
    val freeFormatted: String
)

data class FileItem(
    val id: Int,
    val name: String,
    val folderPath: String,
    val size: Long,
    val sizeFormatted: String,
    val mimeType: String,
    val isImage: Boolean,
    val isVideo: Boolean,
    val isFavorite: Boolean,
    val uploadDate: String,
    val thumbnailUrl: String?,
    val downloadUrl: String,
    val isShared: Boolean = false,
    val shareUrl: String? = null
)

data class FolderItem(
    val name: String,
    val path: String,
    val isShared: Boolean = false,
    val shareUrl: String? = null
)

data class ShareInfo(
    val id: Int,
    val shareToken: String,
    val shareUrl: String,
    val shareMode: String = "read",
    val hasPassword: Boolean = false,
    val expiresAt: String? = null,
    val downloadCount: Int = 0
)

data class BreadcrumbItem(
    val name: String,
    val path: String
)

data class FileListData(
    val currentFolder: String,
    val breadcrumbs: List<BreadcrumbItem>,
    val folders: List<FolderItem>,
    val files: List<FileItem>,
    val quota: Quota?,
    val allFolders: List<String> = emptyList()
)
