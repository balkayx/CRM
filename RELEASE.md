# Sürüm Notları

## Versiyon 1.8.9 (27.06.2025)

### ✨ Yeni Özellikler

- **Genişletilmiş Rol Bazlı Yetkilendirme Sistemi:** Yöneticiler artık "Yönetim Ayarları > Rol Bazlı Yetki Ayarları" panelinden roller için çok daha detaylı yetki kuralları belirleyebilir. 
  
  **Yeni Yetki Kategorileri:**
  - **Müşteri Silme Yetkisi:** Rollerin müşterileri kalıcı olarak silip silemeyeceğini belirleyebilirsiniz
  - **Silinmiş Müşteri Görüntüleme:** Silinmiş müşteri kayıtlarına erişim kontrolü
  - **Veri Dışa Aktarma:** Excel ve PDF formatında veri aktarımı yetkisi
  - **Toplu İşlemler:** Çoklu seçim yaparak toplu işlem gerçekleştirme yetkisi

- **Gelişmiş Süper Yetki Sistemi:** Patron ve Müdür rolleri artık sistemdeki TÜM yetki kontrollerinden muaf tutulmaktadır. Bu roller herhangi bir kısıtlamaya takılmadan tüm işlemleri gerçekleştirebilir.

- **Sürüm Notları Bilgilendirme Sistemi:** Her güncelleme sonrası sisteme ilk girişinizde sizi yeniliklerden haberdar eden akıllı bilgilendirme penceresi karşılayacak. Bu pencere her versiyon için sadece bir kez gösterilir.

### 🛠️ İyileştirmeler ve Değişiklikler

- **Yetki Sistemi Mimarisi Yenilendi:** 
  - Patron (ID: 1) ve Müdür (ID: 2) rolleri için tam yetki bypass sistemi
  - Müdür Yardımcısı, Ekip Lideri ve Müşteri Temsilcisi rolleri için detaylı yetki kontrolü
  - Backend ve frontend tarafında %100 tutarlılık sağlandı

- **Güvenlik İyileştirmeleri:** Yetki altyapısı, tüm işlemlerinizi daha güvenli hale getirmek ve yetkisiz erişimleri önlemek için güncellendi.

- **Performans Optimizasyonları:** Yetki kontrolü sisteminin performansı iyileştirilerek daha hızlı yanıt süreleri sağlandı.

### 📋 Yöneticiler İçin Teknik Notlar

- **Yetki Ayarları:** Yeni yetki kategorileri "Yönetim Ayarları > Rol Bazlı Yetki Ayarları" bölümünden yönetilebilir
- **Rol Hiyerarşisi:** Patron ve Müdür rolleri otomatik olarak tüm yetkilerle donatılmıştır
- **Geriye Uyumluluk:** Mevcut yetki ayarlarınız korunmuş ve yeni sistemle uyumlu hale getirilmiştir

### 💡 Kullanım İpuçları

- **Yetki Planlama:** Yeni yetki kategorilerini kullanarak organizasyonunuza uygun detaylı yetki planlaması yapabilirsiniz
- **Güvenlik:** Hassas işlemler (silme, dışa aktarma) için özel yetkiler tanımlayarak güvenliği artırabilirsiniz
- **Verimlilik:** Toplu işlem yetkilerini uygun rollere vererek iş akışlarını hızlandırabilirsiniz

---

## Versiyon 1.8.1 (Önceki Sürüm)

### Önceki Sürüm Özellikleri
- Temel rol bazlı yetkilendirme sistemi
- Patron yetkisi bypass mekanizması
- Standart müşteri, poliçe ve görev yönetimi yetkileri